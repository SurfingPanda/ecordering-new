<?php

namespace App\Jobs;

use App\Exceptions\EcposRequestException;
use App\Models\Order;
use App\Services\EcposOrders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Sends one posted TR to ECPOS in the background, so posting is instant and a slow or down ECPOS never blocks the store.
 *
 * Safe against duplicates: the order's `ecpos_state` is the guard. Only an order that is `queued` is sent, it is marked
 * `sending` under a row lock first, and once ECPOS has it (`sent`) nothing can send it again. Automatic retries happen
 * only when ECPOS definitely did not get the order (see EcposRequestException).
 */
class SendOrderToEcpos implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MAX_ATTEMPTS = 5;

    /** Seconds to wait before attempt 2, 3, 4 and 5. */
    public const BACKOFF = [30, 120, 300, 900];

    public int $tries = 1; // retries are our own (above), not the queue's: they must follow the rules in EcposRequestException

    public int $timeout = 90;

    public function __construct(public int $orderId) {}

    public function handle(EcposOrders $ecpos): void
    {
        // claim the order: only a queued order is sent, and only by one worker
        $order = DB::transaction(function () {
            $o = Order::whereKey($this->orderId)->lockForUpdate()->first();
            if (! $o || $o->status !== 'posted' || $o->ecpos_sent_at || $o->ecpos_state !== 'queued') {
                return null;
            }
            $o->forceFill(['ecpos_state' => 'sending', 'ecpos_attempts' => $o->ecpos_attempts + 1, 'ecpos_state_at' => now(), 'ecpos_next_at' => null])->save();

            return $o;
        });

        if (! $order) {
            return;
        }

        try {
            $sent = $ecpos->send($order); // the HTTP call happens here, outside any database lock
        } catch (EcposRequestException $e) {
            $this->failed_with($order, $e->getMessage(), retryable: $e->retryable, uncertain: $e->uncertain);

            return;
        } catch (RuntimeException $e) {
            $this->failed_with($order, $e->getMessage(), retryable: false, uncertain: false); // e.g. store not linked, nothing to send

            return;
        }

        $order->forceFill([
            'ecpos_journal_id' => $sent['journal_id'], 'ecpos_sent_at' => now(), 'ecpos_note' => $sent['note'],
            'ecpos_state' => 'sent', 'ecpos_state_at' => now(), 'ecpos_error' => null, 'ecpos_uncertain' => false, 'ecpos_next_at' => null,
        ])->save();
        $order->record('sent', null, 'Sent to ECPOS'.($sent['journal_id'] ? ', journal '.$sent['journal_id'] : ''));
    }

    /** Anything unexpected (a crash, a killed worker): never leave the order silently "sending". */
    public function failed(Throwable $e): void
    {
        $this->failed_with(Order::find($this->orderId), 'Unexpected error while sending to ECPOS: '.$e->getMessage(), retryable: false, uncertain: true);
    }

    private function failed_with(?Order $order, string $message, bool $retryable, bool $uncertain): void
    {
        if (! $order) {
            return;
        }

        $attempt = (int) $order->ecpos_attempts;

        // a queue that runs jobs immediately (sync) can't wait between tries, so it never retries by itself
        if ($retryable && $attempt < self::MAX_ATTEMPTS && config('queue.default') !== 'sync') {
            $wait = self::BACKOFF[$attempt - 1] ?? end(self::BACKOFF);
            $order->forceFill(['ecpos_state' => 'queued', 'ecpos_state_at' => now(), 'ecpos_error' => $message, 'ecpos_uncertain' => false, 'ecpos_next_at' => now()->addSeconds($wait)])->save();
            self::dispatch($order->id)->onQueue($this->queue)->delay($wait); // stay on the same queue

            return;
        }

        $order->forceFill(['ecpos_state' => 'failed', 'ecpos_state_at' => now(), 'ecpos_error' => $message, 'ecpos_uncertain' => $uncertain, 'ecpos_next_at' => null])->save();
        $order->record('send_failed', null, mb_substr($message, 0, 200));
        \App\Support\AdminAlerts::ecposFailed($order);
    }
}

<?php

namespace App\Services;

use App\Models\Item;
use App\Jobs\SendOrderToEcpos;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Sends a posted TR to ECPOS (POST /). Quantity only, no prices. */
class EcposOrders
{
    public function enabled(): bool
    {
        return (bool) config('ecpos.send_orders');
    }

    /**
     * Queues a posted order that is not in ECPOS for another send. Refuses (ValidationException) one ECPOS already has,
     * one already being sent, or one that can't be sent at all, so it never happens twice for the same order.
     */
    public function requeue(Order $order, User $by): void
    {
        DB::transaction(function () use ($order, $by) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'posted' || $fresh->ecpos_sent_at || in_array($fresh->ecpos_state, ['queued', 'sending'], true)) {
                throw ValidationException::withMessages(['ecpos' => $fresh->ecpos_sent_at
                    ? "{$fresh->number()} was already sent to ECPOS."
                    : (in_array($fresh->ecpos_state, ['queued', 'sending'], true) ? "{$fresh->number()} is already being sent." : 'Only a posted order can be sent to ECPOS.')]);
            }

            try {
                $this->prepare($fresh); // refuse right away if it can't be sent at all
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['ecpos' => $e->getMessage()]);
            }

            $fresh->forceFill(['ecpos_state' => 'queued', 'ecpos_attempts' => 0, 'ecpos_error' => null, 'ecpos_uncertain' => false, 'ecpos_state_at' => now(), 'ecpos_next_at' => null])->save();
            $fresh->record('send_requested', $by, 'Sending to ECPOS again');
            SendOrderToEcpos::dispatch($fresh->id)->afterCommit();
        });
    }

    /**
     * Works out what would be sent and checks the order can be sent at all. Throws a RuntimeException with a message fit
     * to show the user when it can't (store not linked to ECPOS, no ECPOS items); posting is refused up front in that case.
     *
     * @return array{payload: array<string, mixed>, note: ?string}
     */
    public function prepare(Order $order): array
    {
        $order->loadMissing(['store', 'user', 'lines']);

        $storeId = $order->store?->ecpos_store_id;
        if (! $storeId) {
            throw new RuntimeException(($order->store?->name ?? 'This store').' isn’t linked to an ECPOS store yet. Ask an admin to set its ECPOS store ID.');
        }

        // only items that come from ECPOS have an ECPOS item code
        $known = Item::whereIn('id', $order->lines->pluck('item_id')->filter())->whereNotNull('synced_at')->pluck('product_code', 'id');
        $sendable = $order->lines->filter(fn ($l) => $known->has($l->item_id));
        $left = $order->lines->reject(fn ($l) => $known->has($l->item_id));

        if ($sendable->isEmpty()) {
            throw new RuntimeException('None of the items in this order exist in ECPOS, so there is nothing to send.');
        }

        $note = $left->isEmpty() ? null : 'Not sent to ECPOS (not ECPOS items): '.$left->map(fn ($l) => "{$l->product_code} ×{$l->quantity}")->implode(', ');

        return [
            'payload' => [
                'store' => $storeId,
                'staff' => $order->staff_name ?: $order->user?->name,
                'description' => trim($order->reference().($order->notes ? ' — '.$order->notes : '')),
                'items' => $sendable->groupBy('product_code')->map(fn ($g, $code) => ['itemid' => (string) $code, 'qty' => (int) $g->sum('quantity')])->values()->all(),
            ],
            'note' => $note,
        ];
    }

    /**
     * Sends the order (used by the background job). Throws EcposRequestException when ECPOS can't take it, with flags
     * saying whether a retry is safe.
     *
     * @return array{journal_id: ?string, note: ?string, raw: array<int|string, mixed>}
     */
    public function send(Order $order): array
    {
        ['payload' => $payload, 'note' => $note] = $this->prepare($order);

        $answer = app(EcposClient::class)->post('', $payload);

        return ['journal_id' => $this->journalId($answer), 'note' => $note, 'raw' => $answer];
    }
    /**
     * What ECPOS says about an order we already sent (GET /{journalid}). Cached for a short time so a page that is
     * refreshed repeatedly doesn't hammer ECPOS.
     *
     * @return array{status: ?string, store: ?string, received_at: ?string, items: int, units: int}
     */
    public function status(string $journalId): array
    {
        return \Illuminate\Support\Facades\Cache::remember('ecpos-order-'.$journalId, 20, function () use ($journalId) {
            $a = app(EcposClient::class)->get('/'.rawurlencode($journalId));
            $items = is_array($a['items'] ?? null) ? $a['items'] : [];

            return [
                'status' => isset($a['status']) && is_scalar($a['status']) ? (string) $a['status'] : null,
                'store' => isset($a['store']) && is_scalar($a['store']) ? (string) $a['store'] : null,
                'received_at' => isset($a['created_at']) && is_scalar($a['created_at']) ? (string) $a['created_at'] : null,
                'items' => count($items),
                'units' => (int) collect($items)->sum(fn ($i) => (float) ($i['qty'] ?? 0)),
            ];
        });
    }

    /** ECPOS's reference for the order; the answer's exact shape isn't documented, so look in the usual places. */
    private function journalId(array $answer): ?string
    {
        foreach ([$answer, $answer['data'] ?? [], $answer['order'] ?? []] as $where) {
            if (! is_array($where)) {
                continue;
            }
            foreach (['journalid', 'journal_id', 'JOURNALID', 'id'] as $key) {
                if (isset($where[$key]) && is_scalar($where[$key]) && (string) $where[$key] !== '') {
                    return (string) $where[$key];
                }
            }
        }

        return null;
    }
}

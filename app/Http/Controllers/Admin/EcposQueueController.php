<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\EcposOrders;
use App\Support\SchedulerHeartbeat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "ECPOS Queue": every posted order that has not reached ECPOS (failed, stuck, still on its way, or posted while sending was off)
 * in one place, with a way to send the safe ones again in one go. An area manager only sees the stores given to them.
 */
class EcposQueueController extends Controller
{
    private const LIMIT = 200;

    public function index(Request $request, EcposOrders $ecpos): Response
    {
        $rows = $this->unsent($request)->with(['store:id,code,name', 'user:id,name'])->withUnits()->orderBy('orders.id')->limit(self::LIMIT)->get()->map(fn (Order $o) => [
            'id' => $o->id,
            'number' => $o->number(),
            'store' => $o->store ? $o->store->code.' — '.$o->store->name : null,
            'order_date' => $o->order_date?->toDateString(),
            'posted_at' => $o->posted_at?->toIso8601String(),
            'units' => (int) $o->units,
            'kind' => $this->kind($o),
            'attempts' => (int) $o->ecpos_attempts,
            'error' => $o->ecpos_error,
            'uncertain' => (bool) $o->ecpos_uncertain,
            'since' => $o->ecpos_state_at?->toIso8601String(),
            'next_at' => $o->ecpos_next_at?->toIso8601String(),
        ])->values();

        $beat = SchedulerHeartbeat::lastBeat();

        return Inertia::render('admin/ecpos-queue', [
            'orders' => $rows,
            'sending_enabled' => $ecpos->enabled(),
            'scheduler' => ['stale' => SchedulerHeartbeat::isStale(), 'last_beat' => $beat ? now()->setTimestamp($beat)->toIso8601String() : null],
            'counts' => $rows->countBy('kind'),
            'safe_to_retry' => $rows->filter(fn ($r) => $this->retryable($r['kind'], $r['uncertain']))->count(),
        ]);
    }

    /** Sends again every order that is safe to: failed (ECPOS definitely did not get it) or never sent. Uncertain ones, and ones still in the queue, are left alone. */
    public function retryAll(Request $request, EcposOrders $ecpos): RedirectResponse
    {
        abort_unless($ecpos->enabled(), 404);

        $queued = 0;
        $skipped = 0;
        $this->unsent($request)->orderBy('orders.id')->limit(self::LIMIT)->get()->each(function (Order $o) use ($ecpos, $request, &$queued, &$skipped) {
            if (! $this->retryable($this->kind($o), (bool) $o->ecpos_uncertain)) {
                return;
            }
            try {
                $ecpos->requeue($o, $request->user());
                $queued++;
            } catch (ValidationException) {
                $skipped++; // not sendable (e.g. a store not linked to ECPOS): it stays on the list with its reason
            }
        });

        return back()->with('success', $queued === 0 && $skipped === 0
            ? 'Nothing to send again.'
            : "{$queued} ".($queued === 1 ? 'order is' : 'orders are').' being sent to ECPOS.'.($skipped ? " {$skipped} could not be sent, open them to see why." : ''));
    }

    /** posted orders ECPOS does not have, for the stores this person looks after */
    private function unsent(Request $request)
    {
        $ids = $request->user()->visibleStoreIds();

        return Order::where('orders.status', 'posted')->whereNull('orders.ecpos_sent_at')
            ->when(! app(EcposOrders::class)->enabled(), fn ($q) => $q->whereNotNull('orders.ecpos_state')) // with sending off, a never-queued order is normal
            ->when($ids !== null, fn ($q) => $q->whereIn('orders.store_id', $ids));
    }

    /** failed | stuck (in the queue 10+ minutes) | sending (on its way) | not_sent (posted but never queued) */
    private function kind(Order $o): string
    {
        return match (true) {
            $o->ecpos_state === 'failed' => 'failed',
            in_array($o->ecpos_state, ['queued', 'sending'], true) => $o->ecpos_state_at && $o->ecpos_state_at->lt(now()->subMinutes(10)) ? 'stuck' : 'sending',
            default => 'not_sent',
        };
    }

    private function retryable(string $kind, bool $uncertain): bool
    {
        return ! $uncertain && in_array($kind, ['failed', 'not_sent'], true);
    }
}

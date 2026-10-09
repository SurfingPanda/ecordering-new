<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Order;
use RuntimeException;

/** Sends a posted TR to ECPOS (POST /). Quantity only, no prices. */
class EcposOrders
{
    public function enabled(): bool
    {
        return (bool) config('ecpos.send_orders');
    }

    /**
     * Builds the request and sends it. Throws a RuntimeException with a message fit to show the user when the order
     * can't be sent; the caller then leaves the TR pending so it can be posted again.
     *
     * @return array{journal_id: ?string, note: ?string, raw: array<int|string, mixed>}
     */
    public function send(Order $order): array
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

        $payload = [
            'store' => $storeId,
            'staff' => $order->user?->name,
            'description' => trim($order->number().($order->notes ? ' — '.$order->notes : '')),
            'items' => $sendable->groupBy('product_code')->map(fn ($g, $code) => ['itemid' => (string) $code, 'qty' => (int) $g->sum('quantity')])->values()->all(),
        ];

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

<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use Carbon\CarbonImmutable;

/**
 * The numbers behind the daily summary email: for a set of stores (an area manager's, or all of them) and one day, which
 * stores ordered and which haven't, how much, and which orders still haven't reached ECPOS.
 */
class DailySummary
{
    public function __construct(private readonly SubmissionDeadline $deadlines) {}

    /**
     * @param  list<int>|null  $storeIds  null = every active store
     * @return array{date: string, total: int, ordered: int, waiting: int, units: int, cancelled: int, ecpos_problems: int, ordered_stores: list<array>, waiting_stores: list<array>}
     */
    public function build(?array $storeIds, CarbonImmutable $day): array
    {
        $date = $day->toDateString();
        $stores = Store::where('is_active', true)->when($storeIds !== null, fn ($q) => $q->whereIn('id', $storeIds))->orderBy('name')->get();
        $ids = $stores->pluck('id');

        $orders = Order::whereIn('status', Order::ACTIVE)->where('order_date', $date)->whereIn('store_id', $ids)
            ->withUnits()->orderBy('id')->get()->groupBy('store_id');

        $ordered = [];
        $waiting = [];
        foreach ($stores as $store) {
            if ($mine = $orders->get($store->id)) {
                $ordered[] = [
                    'name' => $store->name,
                    'code' => $store->code,
                    'orders' => $mine->count(),
                    'units' => (int) $mine->sum('units'),
                    'items' => (int) $mine->sum('lines_count'),
                    'number' => $mine->first()->number(),
                ];

                continue;
            }

            $d = $this->deadlines->for($store, $day);
            $waiting[] = ['name' => $store->name, 'code' => $store->code, 'cutoff' => $d['time'], 'still_open' => now()->lessThanOrEqualTo($d['at'])];
        }

        // a failed send, or one stuck in the queue for 10+ minutes (the same rule as the sidebar badge); any day, it still needs someone
        $problems = Order::ecposProblems()->whereIn('store_id', $ids)->count();

        return [
            'date' => $date,
            'total' => $stores->count(),
            'ordered' => count($ordered),
            'waiting' => count($waiting),
            'units' => array_sum(array_column($ordered, 'units')),
            'cancelled' => Order::where('status', 'cancelled')->where('order_date', $date)->whereIn('store_id', $ids)->count(),
            'ecpos_problems' => $problems,
            'ordered_stores' => $ordered,
            'waiting_stores' => $waiting,
        ];
    }
}

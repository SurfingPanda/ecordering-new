<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `daily_item_totals` (and `daily_catalog_totals`, the same thing added up over all stores) in step with the orders.
 *
 * The dashboard and the trends page add up what was ordered over weeks or months. Doing that from `order_items` means
 * reading every line of every order in the period (over a million rows after a couple of years). This table holds the same
 * totals already added up, one row per day + store + product + catalog, counting only PLACED orders (pending and posted), so
 * those pages read a small fraction of the rows.
 *
 * It is rebuilt one day-and-store at a time whenever an order enters or leaves the counted statuses, changes day or
 * store, or has its lines replaced (see App\Providers\AppServiceProvider and OrderController). `summary:rebuild` rebuilds
 * everything and runs nightly as a safety net.
 */
class OrderSummary
{
    private const CATALOG_COLUMNS = ['order_date', 'product_code', 'source', 'category', 'name', 'units', 'order_count'];

    private const COLUMNS = ['order_date', 'store_id', 'product_code', 'source', 'category', 'name', 'units', 'order_count'];

    /** While true, the model observers leave the totals alone (see withoutRefresh). */
    private static bool $paused = false;

    /**
     * Runs $work without the observers rebuilding the totals after every line, for code that replaces all of an order's
     * lines at once and calls refreshOrder() itself afterwards. Other model events still fire as usual.
     */
    public static function withoutRefresh(callable $work): mixed
    {
        $before = self::$paused;
        self::$paused = true;

        try {
            return $work();
        } finally {
            self::$paused = $before;
        }
    }

    public static function isPaused(): bool
    {
        return self::$paused;
    }

    /** Recomputes one store's totals for one day from the order lines. A store of null/0 is "orders without a store". */
    public static function refreshBucket(?int $storeId, string $date): void
    {
        $date = substr($date, 0, 10);

        DB::transaction(function () use ($storeId, $date) {
            DB::table('daily_item_totals')->where('order_date', $date)->where('store_id', (int) $storeId)->delete();

            $select = self::counted()->where('orders.order_date', $date);
            $storeId ? $select->where('orders.store_id', $storeId) : $select->whereNull('orders.store_id');

            DB::table('daily_item_totals')->insertUsing(self::COLUMNS, $select);

            self::refreshCatalogDay($date);
        });
    }

    /** Re-adds one day of daily_item_totals across all stores into daily_catalog_totals. */
    private static function refreshCatalogDay(string $date): void
    {
        DB::table('daily_catalog_totals')->where('order_date', $date)->delete();
        DB::table('daily_catalog_totals')->insertUsing(self::CATALOG_COLUMNS, self::acrossStores()->where('order_date', $date));
    }

    /** daily_item_totals summed over the stores, in the column order of daily_catalog_totals. */
    private static function acrossStores(): Builder
    {
        return DB::table('daily_item_totals')
            ->groupBy('order_date', 'product_code', 'source')
            ->selectRaw('order_date, product_code, source, MAX(category), MAX(name), SUM(units), SUM(order_count)');
    }

    /** Recomputes the day-and-store bucket an order belongs to. */
    public static function refreshOrder(Order $order): void
    {
        self::refreshBucket($order->store_id, $order->order_date->toDateString());
    }

    /**
     * Rebuilds the totals from scratch (or, with $days, only for orders dated in the last $days days).
     *
     * @return int rows written
     */
    public static function rebuild(?int $days = null): int
    {
        $from = $days ? now()->subDays($days)->toDateString() : null;

        $written = 0;
        DB::transaction(function () use ($from, &$written) {
            DB::table('daily_item_totals')->when($from, fn ($q) => $q->where('order_date', '>=', $from))->delete();
            DB::table('daily_catalog_totals')->when($from, fn ($q) => $q->where('order_date', '>=', $from))->delete();

            // a month at a time, so one statement never has to hold the whole history
            $months = DB::table('orders')->whereIn('status', Order::ACTIVE)->when($from, fn ($q) => $q->where('order_date', '>=', $from))
                ->selectRaw('MIN(order_date) as first, MAX(order_date) as last')->first();

            if (! $months || ! $months->first) {
                return;
            }

            $cursor = new \DateTimeImmutable(substr((string) $months->first, 0, 7).'-01');
            $last = new \DateTimeImmutable(substr((string) $months->last, 0, 10));

            while ($cursor <= $last) {
                $start = $cursor->format('Y-m-d');
                $end = $cursor->modify('last day of this month')->format('Y-m-d');

                $written += DB::table('daily_item_totals')->insertUsing(
                    self::COLUMNS,
                    self::counted()->whereBetween('orders.order_date', [$start, $end]),
                );

                DB::table('daily_catalog_totals')->insertUsing(self::CATALOG_COLUMNS, self::acrossStores()->whereBetween('order_date', [$start, $end]));

                $cursor = $cursor->modify('first day of next month');
            }
        });

        return $written;
    }

    /** Placed orders' lines, added up per day + store + product + catalog, in the column order of the table. */
    private static function counted(): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', Order::ACTIVE)
            ->groupBy('orders.order_date', DB::raw('COALESCE(orders.store_id, 0)'), 'order_items.product_code', 'order_items.source')
            ->selectRaw('orders.order_date, COALESCE(orders.store_id, 0), order_items.product_code, order_items.source, MAX(order_items.category), MAX(order_items.name), SUM(order_items.quantity), COUNT(DISTINCT orders.id)');
    }
}

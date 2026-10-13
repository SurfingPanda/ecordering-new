<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Order;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Ordering Trends": how much each store and each item is ordering, week by week or month by month, so an admin can
 * see who is ordering more or less than before and which items are moving. Counts placed orders (pending + posted).
 *
 * The newest period is still running, so it is shown but never compared; "latest" and "previous" are the last two
 * COMPLETE periods.
 */
class TrendsController extends Controller
{
    private const PERIODS = 12;

    private const ITEM_ROWS = 40;

    /** How long a trends page is reused: it adds up a year of order lines. */
    private const CACHE_SECONDS = 60;

    public function __invoke(Request $request): Response
    {
        $by = $request->query('by') === 'month' ? 'month' : 'week';
        $source = in_array($request->query('source'), Item::SOURCES, true) ? (string) $request->query('source') : null;

        $ids = $request->user()->visibleStoreIds(); // null = every store (admin); an area manager only sees their own

        $props = Cache::remember('trends:'.md5(json_encode([$by, $source, $ids])).':'.today()->toDateString(), self::CACHE_SECONDS, fn () => $this->props($by, $source, $ids));

        return Inertia::render('admin/trends', $props);
    }

    private function props(string $by, ?string $source, ?array $ids): array
    {
        $periods = $this->periods($by);
        $from = $periods[0]['from'];
        $to = end($periods)['to'];
        $slot = fn (string $date) => $this->slotOf($periods, $date);
        $n = count($periods);

        // ---- the whole business, per period
        $total = array_fill(0, $n, 0);
        $orders = array_fill(0, $n, 0);
        foreach (DB::table('orders')->whereIn('status', Order::ACTIVE)->when($ids !== null, fn ($q) => $q->whereIn('store_id', $ids))->whereBetween('order_date', [$from, $to])->get(['order_date']) as $o) {
            if (($i = $slot(substr((string) $o->order_date, 0, 10))) !== null) {
                $orders[$i]++;
            }
        }

        // ---- stores: units per store per period
        $perStore = [];
        // what each store ordered, from the daily totals (see App\Services\OrderSummary) rather than the order lines
        $totals = fn () => ($ids === null ? DB::table('daily_catalog_totals') : DB::table('daily_item_totals')->whereIn('store_id', $ids))->whereBetween('order_date', [$from, $to]);
        $perStoreTotals = fn () => DB::table('daily_item_totals')->when($ids !== null, fn ($q) => $q->whereIn('store_id', $ids))->whereBetween('order_date', [$from, $to]);

        foreach ($perStoreTotals()->groupBy('store_id', 'order_date')->get(['store_id', 'order_date as day', DB::raw('SUM(units) as units')]) as $r) {
            if (($i = $slot(substr((string) $r->day, 0, 10))) === null) {
                continue;
            }
            $perStore[$r->store_id] ??= array_fill(0, $n, 0);
            $perStore[$r->store_id][$i] += (int) $r->units;
            $total[$i] += (int) $r->units;
        }

        $stores = Store::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->get(['id', 'code', 'name', 'is_active'])
            ->filter(fn (Store $s) => $s->is_active || isset($perStore[$s->id]))
            ->map(function (Store $s) use ($perStore, $n) {
                $series = $perStore[$s->id] ?? array_fill(0, $n, 0);

                return ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'active' => (bool) $s->is_active, 'series' => $series] + $this->compare($series);
            })
            ->sortByDesc(fn ($r) => [$r['latest'], $r['sum']])->values()->all();

        // ---- items: units per item per period (optionally one catalog)
        $perItem = [];
        $meta = [];
        $itemQuery = $totals()->when($source, fn ($q) => $q->where('source', $source));
        foreach ($itemQuery->groupBy('product_code', 'order_date')->get(['product_code as code', DB::raw('MAX(name) as name'), DB::raw('MAX(source) as source'), DB::raw('MAX(category) as category'), 'order_date as day', DB::raw('SUM(units) as units')]) as $r) {
            if (($i = $slot(substr((string) $r->day, 0, 10))) === null) {
                continue;
            }
            $perItem[$r->code] ??= array_fill(0, $n, 0);
            $perItem[$r->code][$i] += (int) $r->units;
            $meta[$r->code] = ['name' => $r->name, 'source' => $r->source, 'category' => $r->category];
        }

        $items = collect($perItem)->map(fn (array $series, string $code) => ['code' => $code] + $meta[$code] + ['series' => $series] + $this->compare($series))
            ->sortByDesc(fn ($r) => [$r['latest'], $r['sum']])->values();

        return [
            'by' => $by,
            'source' => $source,
            'periods' => array_map(fn ($p) => ['label' => $p['label'], 'long' => $p['long'], 'partial' => $p['partial']], $periods),
            'overall' => ['units' => $total, 'orders' => $orders] + $this->compare($total),
            'stores' => $stores,
            'items' => $items->take(self::ITEM_ROWS)->all(),
            'item_count' => $items->count(),
            'quiet_stores' => collect($stores)->filter(fn ($s) => $s['active'] && $s['latest'] === 0 && $s['previous'] > 0)->pluck('name')->values()->all(),
        ];
    }

    /** The last 12 weeks (Monday to Sunday) or calendar months, oldest first; the final one is the running period. */
    private function periods(string $by): array
    {
        $today = CarbonImmutable::today();
        $current = $by === 'month' ? $today->startOfMonth() : $today->startOfWeek();
        $out = [];

        for ($k = self::PERIODS - 1; $k >= 0; $k--) {
            $start = $by === 'month' ? $current->subMonths($k) : $current->subWeeks($k);
            $end = $by === 'month' ? $start->endOfMonth() : $start->endOfWeek();
            $out[] = [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'label' => $by === 'month' ? $start->format('M') : $start->format('M j'),
                'long' => $by === 'month' ? $start->format('F Y') : $start->format('M j').' – '.$end->format('M j, Y'),
                'partial' => $k === 0,
            ];
        }

        return $out;
    }

    private function slotOf(array $periods, string $date): ?int
    {
        foreach ($periods as $i => $p) {
            if ($date >= $p['from'] && $date <= $p['to']) {
                return $i;
            }
        }

        return null;
    }

    /** Latest vs previous COMPLETE period (the running one, last in the series, is left out), plus the total over the range. */
    private function compare(array $series): array
    {
        $n = count($series);
        $latest = $series[$n - 2];
        $previous = $series[$n - 3];

        return [
            'latest' => $latest,
            'previous' => $previous,
            'change' => $previous > 0 ? (int) round((($latest - $previous) / $previous) * 100) : null, // null: nothing before to compare with
            'sum' => array_sum($series),
        ];
    }
}

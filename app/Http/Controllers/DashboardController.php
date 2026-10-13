<?php

namespace App\Http\Controllers;

use App\Models\DailyCatalogTotal;
use App\Models\DailyItemTotal;
use App\Models\Item;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const RANGES = [7, 30, 90];

    /** How long the dashboard totals are reused. */
    private const CACHE_SECONDS = 60;

    /** Orders that count as "ordered" in reports: every placed order (pending or posted). */
    private const COUNTED = Order::ACTIVE;

    /** Set for store accounts (their own store) and area managers (their stores) so every figure is limited to them; null for admins (all stores). */
    private ?array $storeIds = null;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $this->storeIds = $user->visibleStoreIds();

        $days = in_array((int) $request->query('range'), self::RANGES, true) ? (int) $request->query('range') : 30;

        $end = CarbonImmutable::now()->endOfDay();
        $start = $end->subDays($days - 1)->startOfDay();
        $prevEnd = $start->subSecond();
        $prevStart = $start->subDays($days);

        // The totals scan every order line in the period, so they are kept for a minute per audience (a store, an area
        // manager's stores or everyone) and range. The "recent orders" list below is never cached, so a new order shows at once.
        $key = 'dashboard:'.md5(json_encode([$this->storeIds, $days])).':'.$end->toDateString();
        $totals = Cache::remember($key, self::CACHE_SECONDS, fn () => [
            'kpis' => [
                'orders' => $this->kpi($this->orderCount($start, $end), $this->orderCount($prevStart, $prevEnd)),
                'units' => $this->kpi($this->unitCount($start, $end), $this->unitCount($prevStart, $prevEnd)),
                'pending' => Order::where('status', 'pending')->when($this->storeIds !== null, fn ($q) => $q->whereIn('store_id', $this->storeIds))->count(),
                'items_ordered' => $this->totals($start, $end)->distinct()->count('product_code'),
            ],
            'daily' => $this->daily($start, $days),
            'split' => $this->split($start, $end),
            'top_items' => collect(Item::SOURCES)->mapWithKeys(fn (string $s) => [$s => $this->topItems($start, $end, $s)])->all(),
            'top_categories' => $this->topCategories($start, $end),
            'not_ordered' => $this->notOrdered($start, $end),
        ]);

        return Inertia::render('dashboard', [
            'range' => $days,
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            ...$totals,
            'scope' => $user->isStore() ? 'store' : 'all', // 'all' = several stores, so the lists show a Store column
            'recent' => Order::with(['user:id,name', 'store:id,code,name'])->visibleTo($user)
                ->where('status', '!=', Order::DRAFT)
                ->withUnits()
                ->orderByDesc('order_date')->orderByDesc('id')->limit(6)->get()
                ->map(fn (Order $o) => [
                    'id' => $o->id,
                    'number' => $o->number(),
                    'ordered_by' => $o->user->name,
                    'store' => $o->store ? ['id' => $o->store->id, 'code' => $o->store->code, 'name' => $o->store->name] : null,
                    'status' => $o->status,
                    'lines_count' => $o->lines_count,
                    'units' => (int) $o->units,
                    'bw_units' => (int) $o->bw_units,
                    'warehouse_units' => (int) $o->warehouse_units,
                    'merchandise_units' => (int) $o->merchandise_units,
                    'rejects_units' => (int) $o->rejects_units,
                    'order_date' => $o->order_date->toDateString(),
                    'created_at' => $o->created_at->toIso8601String(),
                ]),
        ]);
    }

    /**
     * What was ordered between $from and $to, from the daily totals (see App\Services\OrderSummary): one row per day,
     * store, product and catalog, counting placed orders only. Far fewer rows than the order lines they add up.
     */
    private function totals(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        // everyone-at-once (admin) reads the all-stores rollup; a store or area manager reads their own stores' rows
        return ($this->storeIds === null ? DailyCatalogTotal::query() : DailyItemTotal::query()->whereIn('store_id', $this->storeIds))
            ->whereBetween('order_date', [$from->toDateString(), $to->toDateString()]);
    }

    private function orderCount(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Order::whereIn('status', self::COUNTED)->when($this->storeIds !== null, fn ($q) => $q->whereIn('store_id', $this->storeIds))->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])->count();
    }

    private function unitCount(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $this->totals($from, $to)->sum('units');
    }

    /** Current value plus % change versus the previous period (null when there is nothing to compare). */
    private function kpi(int $current, int $previous): array
    {
        return [
            'value' => $current,
            'previous' => $previous,
            'change' => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
        ];
    }

    /** Units per day, split by catalog, with empty days filled in so the chart has no gaps. */
    private function daily(CarbonImmutable $start, int $days): array
    {
        $rows = $this->totals($start, $start->addDays($days - 1)->endOfDay())
            ->selectRaw('order_date as day, source, SUM(units) as units')
            ->groupBy('day', 'source')
            ->get()
            ->groupBy('day');

        return collect(range(0, $days - 1))->map(function (int $i) use ($start, $rows) {
            $date = $start->addDays($i)->toDateString();
            $byDay = ($rows[$date] ?? collect())->pluck('units', 'source');

            return ['date' => $date] + collect(Item::SOURCES)->mapWithKeys(fn (string $s) => [$s => (int) ($byDay[$s] ?? 0)])->all();
        })->all();
    }

    private function split(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->totals($start, $end)
            ->selectRaw('source, SUM(units) as units, COUNT(DISTINCT product_code) as items')
            ->groupBy('source')
            ->get()
            ->keyBy('source');

        return collect(Item::SOURCES)->mapWithKeys(fn (string $s) => [
            $s => ['units' => (int) ($rows[$s]->units ?? 0), 'items' => (int) ($rows[$s]->items ?? 0)],
        ])->all();
    }

    private function topItems(CarbonImmutable $start, CarbonImmutable $end, string $source): array
    {
        return $this->totals($start, $end)
            ->where('source', $source)
            ->selectRaw('product_code, MAX(name) as name, MAX(category) as category, SUM(units) as units, SUM(order_count) as orders')
            ->groupBy('product_code')
            ->orderByDesc('units')
            ->orderBy('product_code')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'product_code' => $r->product_code,
                'name' => $r->name,
                'category' => $r->category,
                'units' => (int) $r->units,
                'orders' => (int) $r->orders,
            ])->all();
    }

    private function topCategories(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->totals($start, $end)
            ->whereNotNull('category')
            ->selectRaw('category, source, SUM(units) as units')
            ->groupBy('category', 'source')
            ->orderByDesc('units')
            ->limit(8)
            ->get()
            ->map(fn ($r) => ['category' => $r->category, 'source' => $r->source, 'units' => (int) $r->units])
            ->all();
    }

    /** Catalog items nobody ordered in the period: candidates to review. */
    private function notOrdered(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $ordered = $this->totals($start, $end)->select('product_code');

        return Item::active()->whereNotIn('product_code', $ordered)
            ->orderBy('source')->orderBy('product_code')
            ->limit(8)
            ->get(['product_code', 'description', 'category', 'source'])
            ->map(fn (Item $i) => $i->only('product_code', 'description', 'category', 'source'))
            ->all();
    }
}

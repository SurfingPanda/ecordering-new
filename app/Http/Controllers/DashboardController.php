<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const RANGES = [7, 30, 90];

    /** Orders that count as "ordered" in reports: every placed order (pending or posted). */
    private const COUNTED = Order::ACTIVE;

    /** Set for store accounts so every figure is limited to their own store; null for admins (all stores). */
    private ?int $storeId = null;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $this->storeId = $user->isStore() ? ($user->store_id ?? 0) : null;

        $days = in_array((int) $request->query('range'), self::RANGES, true) ? (int) $request->query('range') : 30;

        $end = CarbonImmutable::now()->endOfDay();
        $start = $end->subDays($days - 1)->startOfDay();
        $prevEnd = $start->subSecond();
        $prevStart = $start->subDays($days);

        return Inertia::render('dashboard', [
            'range' => $days,
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'kpis' => [
                'orders' => $this->kpi($this->orderCount($start, $end), $this->orderCount($prevStart, $prevEnd)),
                'units' => $this->kpi($this->unitCount($start, $end), $this->unitCount($prevStart, $prevEnd)),
                'pending' => Order::where('status', 'pending')->when($this->storeId !== null, fn ($q) => $q->where('store_id', $this->storeId))->count(),
                'items_ordered' => $this->lines($start, $end)->distinct()->count('order_items.product_code'),
            ],
            'daily' => $this->daily($start, $days),
            'split' => $this->split($start, $end),
            'top_items' => collect(Item::SOURCES)->mapWithKeys(fn (string $s) => [$s => $this->topItems($start, $end, $s)])->all(),
            'top_categories' => $this->topCategories($start, $end),
            'not_ordered' => $this->notOrdered($start, $end),
            'scope' => $this->storeId === null ? 'all' : 'store',
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

    /** order_items rows that belong to counted orders within [$from, $to]. */
    private function lines(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', self::COUNTED)
            ->when($this->storeId !== null, fn ($q) => $q->where('orders.store_id', $this->storeId))
            ->whereBetween('orders.order_date', [$from->toDateString(), $to->toDateString()]);
    }

    private function orderCount(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Order::whereIn('status', self::COUNTED)->when($this->storeId !== null, fn ($q) => $q->where('store_id', $this->storeId))->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])->count();
    }

    private function unitCount(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $this->lines($from, $to)->sum('order_items.quantity');
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
        $rows = $this->lines($start, $start->addDays($days - 1)->endOfDay())
            ->selectRaw('orders.order_date as day, order_items.source as source, SUM(order_items.quantity) as units')
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
        $rows = $this->lines($start, $end)
            ->selectRaw('order_items.source as source, SUM(order_items.quantity) as units, COUNT(DISTINCT order_items.product_code) as items')
            ->groupBy('source')
            ->get()
            ->keyBy('source');

        return collect(Item::SOURCES)->mapWithKeys(fn (string $s) => [
            $s => ['units' => (int) ($rows[$s]->units ?? 0), 'items' => (int) ($rows[$s]->items ?? 0)],
        ])->all();
    }

    private function topItems(CarbonImmutable $start, CarbonImmutable $end, string $source): array
    {
        return $this->lines($start, $end)
            ->where('order_items.source', $source)
            ->selectRaw('order_items.product_code as product_code, MAX(order_items.name) as name, MAX(order_items.category) as category, SUM(order_items.quantity) as units, COUNT(DISTINCT orders.id) as orders')
            ->groupBy('order_items.product_code')
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
        return $this->lines($start, $end)
            ->whereNotNull('order_items.category')
            ->selectRaw('order_items.category as category, order_items.source as source, SUM(order_items.quantity) as units')
            ->groupBy('order_items.category', 'order_items.source')
            ->orderByDesc('units')
            ->limit(8)
            ->get()
            ->map(fn ($r) => ['category' => $r->category, 'source' => $r->source, 'units' => (int) $r->units])
            ->all();
    }

    /** Catalog items nobody ordered in the period: candidates to review. */
    private function notOrdered(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $ordered = $this->lines($start, $end)->select('order_items.item_id');

        return Item::active()->whereNotIn('id', $ordered)
            ->orderBy('source')->orderBy('product_code')
            ->limit(8)
            ->get(['product_code', 'description', 'category', 'source'])
            ->map(fn (Item $i) => $i->only('product_code', 'description', 'category', 'source'))
            ->all();
    }
}

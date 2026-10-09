<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Order;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin-only view of what every store ordered. The date basis is the ORDER DATE (the day an order is for),
 * not the moment it was typed in. Quantities are plain units: items have no unit of measure in this system,
 * so there is nothing incompatible to add together.
 */
class ConsolidatedOrderController extends Controller
{
    /** sort key => column. Whitelisted so user input never reaches ORDER BY. */
    private const SORTS = [
        'order_date' => 'orders.order_date',
        'number' => 'orders.tr_number',
        'store_code' => 'stores.code',
        'store_name' => 'stores.name',
        'product_code' => 'order_items.product_code',
        'name' => 'order_items.name',
        'category' => 'order_items.category',
        'type' => 'order_items.source',
        'quantity' => 'order_items.quantity',
        'status' => 'orders.status',
        'submitted' => 'orders.posted_at',
    ];

    public function index(Request $request): Response
    {
        $f = $this->filters($request);
        $base = $this->base($f);

        $detail = null;
        $pivot = null;

        if ($f['view'] === 'products') {
            $pivot = $this->pivot($f);
        } else {
            $detail = (clone $base)
                ->select([
                    'orders.id as order_id', 'orders.tr_number', 'orders.order_date', 'orders.status', 'orders.posted_at', 'orders.created_at as placed_at',
                    'stores.code as store_code', 'stores.name as store_name',
                    'order_items.product_code', 'order_items.name', 'order_items.category', 'order_items.source', 'order_items.quantity',
                ])
                ->orderBy(self::SORTS[$f['sort']], $f['dir'])
                ->orderBy('orders.id')->orderBy('order_items.id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn ($r) => [
                    'order_id' => $r->order_id,
                    'number' => $r->tr_number ?: 'TR'.str_pad((string) $r->order_id, 12, '0', STR_PAD_LEFT),
                    'order_date' => CarbonImmutable::parse($r->order_date)->toDateString(),
                    'store_code' => $r->store_code,
                    'store_name' => $r->store_name,
                    'product_code' => $r->product_code,
                    'name' => $r->name,
                    'category' => $r->category,
                    'source' => $r->source,
                    'quantity' => (int) $r->quantity,
                    'status' => $r->status,
                    'submitted_at' => $r->posted_at ? CarbonImmutable::parse($r->posted_at)->toIso8601String() : null,
                    'placed_at' => CarbonImmutable::parse($r->placed_at)->toIso8601String(),
                ]);
        }

        return Inertia::render('admin/consolidated', [
            'filters' => $f,
            'summary' => $this->summary($f),
            'detail' => $detail,
            'pivot' => $pivot,
            'stores' => Store::orderBy('name')->get(['id', 'code', 'name']),
            'today' => today()->toDateString(),
        ]);
    }

    /** CSV of exactly what's on screen (same filters, same admin-only guard). */
    public function export(Request $request): StreamedResponse
    {
        $f = $this->filters($request);
        $name = 'consolidated-orders-'.$f['from'].'_to_'.$f['to'].($f['view'] === 'products' ? '-by-product' : '').'.csv';

        return response()->streamDownload(function () use ($f) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8

            if ($f['view'] === 'products') {
                $pivot = $this->pivot($f, paginate: false);
                fputcsv($out, ['Product Code', 'Product', 'Item Type', 'Category', ...collect($pivot['stores'])->map(fn ($s) => $s['code'].' - '.$s['name'])->all(), 'Total']);
                foreach ($pivot['rows'] as $r) {
                    fputcsv($out, [
                        $this->csv($r['product_code']), $this->csv($r['name']), Item::SINGULAR[$r['source']] ?? $r['source'], $this->csv($r['category']),
                        ...collect($pivot['stores'])->map(fn ($s) => $r['by_store'][$s['id']] ?? 0)->all(), $r['total'],
                    ]);
                }
            } else {
                fputcsv($out, ['Order Date', 'TR Number', 'Store Code', 'Store', 'Product Code', 'Product', 'Category', 'Item Type', 'Quantity', 'Status', 'Submitted At']);
                $this->base($f)
                    ->select(['orders.id as order_id', 'orders.tr_number', 'orders.order_date', 'orders.status', 'orders.posted_at', 'stores.code as store_code', 'stores.name as store_name',
                        'order_items.product_code', 'order_items.name', 'order_items.category', 'order_items.source', 'order_items.quantity'])
                    ->orderBy(self::SORTS[$f['sort']], $f['dir'])->orderBy('orders.id')->orderBy('order_items.id')
                    ->chunk(500, function ($rows) use ($out) {
                        foreach ($rows as $r) {
                            fputcsv($out, [
                                CarbonImmutable::parse($r->order_date)->toDateString(), $r->tr_number ?: 'TR'.str_pad((string) $r->order_id, 12, '0', STR_PAD_LEFT),
                                $this->csv($r->store_code), $this->csv($r->store_name), $this->csv($r->product_code), $this->csv($r->name), $this->csv($r->category),
                                Item::SINGULAR[$r->source] ?? $r->source, $r->quantity, $r->status,
                                $r->posted_at ? CarbonImmutable::parse($r->posted_at)->format('Y-m-d H:i') : '',
                            ]);
                        }
                    });
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ------------------------------------------------------------------ query building */

    /** Validated, normalised filters (anything unrecognised falls back to a safe default). */
    private function filters(Request $request): array
    {
        $date = fn (?string $v, string $fallback) => $v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : $fallback;
        $today = today()->toDateString();

        $from = $date($request->query('from'), $today);
        $to = $date($request->query('to'), $today);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $in = fn (?string $v, array $allowed) => in_array($v, $allowed, true) ? $v : null;

        return [
            'from' => $from,
            'to' => $to,
            'store' => $request->query('store') ? (int) $request->query('store') : null,
            'status' => $in($request->query('status'), Order::STATUSES),
            'type' => $in($request->query('type'), Item::SOURCES),
            'ref' => trim((string) $request->query('ref', '')),
            'product' => trim((string) $request->query('product', '')),
            'view' => $request->query('view') === 'products' ? 'products' : 'detail',
            'sort' => array_key_exists($request->query('sort', ''), self::SORTS) ? $request->query('sort') : 'order_date',
            'dir' => $request->query('dir') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** order_items ⨝ orders ⨝ stores with every filter applied (drafts never appear). */
    private function base(array $f): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('stores', 'stores.id', '=', 'orders.store_id')
            ->where('orders.status', '!=', Order::DRAFT)
            ->whereBetween('orders.order_date', [$f['from'], $f['to']])
            ->when($f['store'], fn ($q, $v) => $q->where('orders.store_id', $v))
            ->when($f['status'], fn ($q, $v) => $q->where('orders.status', $v))
            ->when($f['type'], fn ($q, $v) => $q->where('order_items.source', $v))
            // "TR-BW0018-000001", "BW0018" or "000001" all work
            ->when($f['ref'] !== '', fn ($q) => $q->where('orders.tr_number', 'like', '%'.$f['ref'].'%'))
            ->when($f['product'] !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('order_items.name', 'like', '%'.$f['product'].'%')
                ->orWhere('order_items.product_code', 'like', '%'.$f['product'].'%')));
    }

    private function summary(array $f): array
    {
        $live = fn () => $this->base($f)->where('orders.status', '!=', 'cancelled'); // cancelled TRs don't count as ordered

        $statusCounts = $this->base($f)
            ->select('orders.status', DB::raw('COUNT(DISTINCT orders.id) as c'))
            ->groupBy('orders.status')->pluck('c', 'status');

        $bySource = $live()->select('order_items.source', DB::raw('SUM(order_items.quantity) as units'))
            ->groupBy('order_items.source')->pluck('units', 'source');

        return [
            'stores' => (int) $live()->distinct()->count('orders.store_id'),
            'orders' => (int) $live()->distinct()->count('orders.id'),
            'products' => (int) $live()->distinct()->count('order_items.product_code'),
            'units' => (int) $live()->sum('order_items.quantity'),
            'bw_units' => (int) ($bySource['bw_products'] ?? 0),
            'warehouse_units' => (int) ($bySource['warehouse'] ?? 0),
            'merchandise_units' => (int) ($bySource['merchandise'] ?? 0),
            'rejects_units' => (int) ($bySource['rejects'] ?? 0),
            'status' => [
                'pending' => (int) ($statusCounts['pending'] ?? 0),
                'posted' => (int) ($statusCounts['posted'] ?? 0),
                'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
            ],
        ];
    }

    /**
     * Product × store matrix. Store columns are generated from the data, so a new store simply appears.
     * Cancelled TRs are excluded unless the status filter is explicitly "cancelled".
     */
    private function pivot(array $f, bool $paginate = true): array
    {
        $q = $this->base($f);
        if ($f['status'] === null) {
            $q->where('orders.status', '!=', 'cancelled');
        }

        $cells = $q->select([
            'order_items.product_code', DB::raw('MAX(order_items.name) as name'), DB::raw('MAX(order_items.category) as category'),
            'order_items.source', 'orders.store_id', DB::raw('SUM(order_items.quantity) as qty'),
        ])->groupBy('order_items.product_code', 'order_items.source', 'orders.store_id')->get();

        $stores = Store::whereIn('id', $cells->pluck('store_id')->filter()->unique())->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name])->values()->all();

        /** @var Collection $rows */
        $rows = $cells->groupBy(fn ($c) => $c->source.'|'.$c->product_code)->map(function ($g) {
            $first = $g->first();
            $byStore = $g->filter(fn ($c) => $c->store_id)->mapWithKeys(fn ($c) => [(int) $c->store_id => (int) $c->qty])->all();

            return [
                'product_code' => $first->product_code,
                'name' => $first->name,
                'category' => $first->category,
                'source' => $first->source,
                'by_store' => $byStore,
                'total' => (int) $g->sum('qty'),
            ];
        })->sortBy([['source', 'asc'], ['product_code', 'asc']])->values();

        $totals = ['total' => $rows->sum('total'), 'by_store' => collect($stores)->mapWithKeys(fn ($s) => [$s['id'] => $rows->sum(fn ($r) => $r['by_store'][$s['id']] ?? 0)])->all()];

        if (! $paginate) {
            return ['stores' => $stores, 'rows' => $rows->all(), 'totals' => $totals];
        }

        $page = max(1, (int) request('page', 1));
        $per = 25;
        $paginator = new LengthAwarePaginator($rows->forPage($page, $per)->values(), $rows->count(), $per, $page, ['path' => request()->url(), 'query' => request()->query()]);

        return ['stores' => $stores, 'rows' => $paginator->items(), 'totals' => $totals, 'meta' => [
            'from' => $paginator->firstItem(), 'to' => $paginator->lastItem(), 'total' => $paginator->total(), 'links' => $paginator->linkCollection()->all(),
        ]];
    }

    /** Neutralise spreadsheet formulas (=, +, -, @) in text cells. */
    private function csv(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}

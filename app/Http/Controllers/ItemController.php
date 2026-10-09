<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Item;
use App\Services\BarcodeGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The product catalog. Items are never deleted: an admin archives them (hidden from the catalog and from
 * ordering) and can restore them later from the Archived Products page.
 */
class ItemController extends Controller
{
    private const SORTABLE = [
        'product_code', 'description', 'barcode',
        'category', 'retail_group', 'archived_at',
    ];

    /** The live catalog: what stores see and can order. */
    public function index(Request $request): Response
    {
        return $this->listing($request, archived: false);
    }

    /** Admin only (route middleware): items that have been archived. */
    public function archived(Request $request): Response
    {
        return $this->listing($request, archived: true);
    }

    private function listing(Request $request, bool $archived): Response
    {
        [$query, $filters] = $this->filtered($request, $archived);

        $items = $query
            ->orderBy($filters['sort'], $filters['dir'])
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Item $i) => $i->toRow());

        return Inertia::render('retails/index', [
            'items' => $items,
            'ecpos' => $request->user()->isAdmin() ? [
                'configured' => (bool) config('ecpos.api_key'),
                'last_sync' => \App\Models\AppSetting::read('ecpos_last_sync'),
            ] : null,
            'view' => $archived ? 'archived' : 'active',
            'basePath' => $archived ? '/admin/archived-products' : '/retails',
            'filters' => $filters,
            // how many items each catalog has in this view (active, or archived), for the catalog switcher
            'counts' => collect(Item::SOURCES)->mapWithKeys(fn (string $s) => [
                $s => Item::where('source', $s)->when($archived, fn ($q) => $q->archived(), fn ($q) => $q->active())->count(),
            ]),
            // category names per catalog, for the Add item dropdown
            'categories' => collect(Item::SOURCES)->mapWithKeys(fn (string $s) => [
                $s => Category::where('source', $s)->orderBy('name')->pluck('name')->values(),
            ]),
        ]);
    }

    /**
     * The filtered item query shared by the list page and the Excel export, so what you download is exactly
     * what the filters on screen select (every page of it, not only the one being shown).
     *
     * @return array{0: Builder, 1: array{source: string, group: ?string, search: string, sort: string, dir: string}}
     */
    private function filtered(Request $request, bool $archived): array
    {
        $source = in_array($request->query('source'), Item::SOURCES, true) ? $request->query('source') : 'bw_products';
        $group = in_array($request->query('group'), Item::RETAIL_GROUPS, true) ? $request->query('group') : null;
        $search = trim((string) $request->query('search', ''));
        $sortable = $archived ? self::SORTABLE : array_diff(self::SORTABLE, ['archived_at']);
        $sort = in_array($request->query('sort'), $sortable, true) ? $request->query('sort') : ($archived ? 'archived_at' : 'product_code');
        $dir = in_array($request->query('dir'), ['asc', 'desc'], true) ? $request->query('dir') : ($archived ? 'desc' : 'asc');

        $query = Item::where('source', $source)
            ->when($archived, fn ($q) => $q->archived(), fn ($q) => $q->active())
            ->when($group, fn ($q) => $q->where('retail_group', $group))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('product_code', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")
                ->orWhere('category', 'like', "%{$search}%")));

        return [$query, compact('source', 'group', 'search', 'sort', 'dir')];
    }

    /** Admin only: downloads the current list (active catalog or archived) as an Excel .xlsx file. */
    public function export(Request $request): BinaryFileResponse
    {
        $archived = $request->boolean('archived');
        [$query, $f] = $this->filtered($request, $archived);

        $catalog = Item::LABELS[$f['source']] ?? $f['source'];
        $columns = ['Product Code', 'Description', 'Barcode', 'Category', 'Retail Group', 'Catalog'];
        if ($archived) {
            $columns[] = 'Archived On';
        }

        $path = tempnam(sys_get_temp_dir(), 'catalog').'.xlsx';

        $options = new Options;
        $options->setColumnWidth(18, 1);
        $options->setColumnWidth(48, 2);
        $options->setColumnWidth(20, 3);
        $options->setColumnWidth(24, 4);
        $options->setColumnWidth(18, 5);
        $options->setColumnWidth(16, 6);
        $options->setColumnWidth(22, 7);

        $writer = new Writer($options);
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName($archived ? "{$catalog} (archived)" : $catalog);
        $sheet->setSheetView((new SheetView)->setFreezeRow(2)); // keep the header row visible while scrolling

        $writer->addRow(Row::fromValues($columns, (new Style)->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor('0A2DBE')));

        // every value is written as plain text/data, so nothing in the catalog is ever evaluated as a formula
        $count = 0;
        foreach ($query->orderBy($f['sort'], $f['dir'])->orderBy('id')->cursor() as $item) {
            $row = [
                $item->product_code,
                $item->description,
                (string) ($item->barcode ?? ''),
                (string) ($item->category ?? ''),
                $item->retail_group === 'non_product' ? 'Non-product' : 'Regular Product',
                $catalog,
            ];
            if ($archived) {
                $row[] = $item->archived_at?->format('Y-m-d H:i') ?? '';
            }
            $writer->addRow(Row::fromValues($row));
            $count++;
        }

        $sheet->setAutoFilter(new AutoFilter(0, 1, count($columns) - 1, max(2, $count + 1))); // filter arrows on the header
        $writer->close();

        $name = str($catalog)->slug().($archived ? '-archived' : '-catalog').'-'.now()->format('Y-m-d').'.xlsx';

        return response()->download($path, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** Every active item (both catalogs), so the catalog page's refresh button can tell what was added or archived since it last looked. */
    public function snapshot(): JsonResponse
    {
        return response()->json([
            'items' => Item::active()->orderBy('id')->get(['id', 'source', 'product_code', 'description']),
        ]);
    }

    /** Admin only: a fresh, unused 13-digit barcode for the Add item form (and its refresh button). */
    public function barcode(BarcodeGenerator $generator): JsonResponse
    {
        return response()->json(['barcode' => $generator->generate()]);
    }

    /**
     * Admin only: is this barcode free? Used by the Add item form to warn about a duplicate as soon as a
     * barcode is typed or generated. (Saving re-checks it on the server, so this is only an early warning.)
     */
    public function checkBarcode(Request $request): JsonResponse
    {
        $barcode = trim((string) $request->query('barcode', ''));

        if (preg_match('/^\d{13}$/', $barcode) !== 1) {
            return response()->json(['valid' => false, 'available' => false, 'used_by' => null]);
        }

        $owner = Item::where('barcode', $barcode)->first(['product_code', 'description', 'source', 'archived_at']);

        return response()->json([
            'valid' => true,
            'available' => $owner === null,
            'used_by' => $owner ? [
                'product_code' => $owner->product_code,
                'description' => $owner->description,
                'source' => $owner->source,
                'archived' => $owner->archived_at !== null,
            ] : null,
        ]);
    }

    /**
     * Admin only: is this product code / description already taken? Early warning for the Add item form (saving re-checks).
     * Product codes are unique across the whole catalog (archived items included); descriptions are unique within a catalog.
     */
    public function checkDuplicate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', Rule::in(['product_code', 'description'])],
            'value' => ['required', 'string', 'max:255'],
            'source' => ['nullable', Rule::in(Item::SOURCES)],
        ]);

        $query = Item::where($data['field'], trim($data['value']))
            ->when($data['field'] === 'description' && ! empty($data['source']), fn ($q) => $q->where('source', $data['source']));

        $owner = $query->first(['product_code', 'description', 'source', 'archived_at']);

        return response()->json([
            'taken' => $owner !== null,
            'used_by' => $owner ? [
                'product_code' => $owner->product_code,
                'description' => $owner->description,
                'source' => $owner->source,
                'archived' => $owner->archived_at !== null,
            ] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $item = Item::create($this->validated($request));

        return to_route('retails.index', ['source' => $item->source])->with('success', "{$item->product_code} added.");
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $item->update($this->validated($request, $item));

        return to_route('retails.index', ['source' => $item->source])->with('success', "{$item->product_code} updated.");
    }

    /** Archives the selected items: hidden from the catalog and from ordering, nothing is deleted. */
    public function archive(Request $request): RedirectResponse
    {
        $items = $this->selected($request)->active()->get();
        Item::whereKey($items->modelKeys())->update(['archived_at' => now()]);

        $n = $items->count();

        return to_route('retails.index', ['source' => $items->first()?->source ?? 'bw_products'])
            ->with('success', $n === 1 ? '1 item archived.' : "{$n} items archived.");
    }

    /** Brings archived items back into the catalog and onto the order sheet. */
    public function restore(Request $request): RedirectResponse
    {
        $items = $this->selected($request)->archived()->get();
        Item::whereKey($items->modelKeys())->update(['archived_at' => null]);

        $n = $items->count();

        return to_route('admin.archived', ['source' => $items->first()?->source ?? 'bw_products'])
            ->with('success', $n === 1 ? '1 item restored.' : "{$n} items restored.");
    }

    private function selected(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ], ['ids.required' => 'Select at least one item.', 'ids.min' => 'Select at least one item.']);

        return Item::whereIn('id', $data['ids']);
    }

    private function validated(Request $request, ?Item $item = null): array
    {
        // surrounding spaces never make two codes/descriptions "different"
        $request->merge([
            'product_code' => trim((string) $request->input('product_code')),
            'description' => trim((string) $request->input('description')),
        ]);

        return $request->validate([
            'source' => ['required', Rule::in(Item::SOURCES)],
            'product_code' => ['required', 'string', 'max:64', Rule::unique('items', 'product_code')->ignore($item)],
            'description' => ['required', 'string', 'max:255', Rule::unique('items', 'description')->where('source', $request->input('source'))->ignore($item)],
            // required: 13 digits, and never shared with another item (archived ones included)
            'barcode' => ['required', 'digits:13', Rule::unique('items', 'barcode')->ignore($item)],
            // must be one of the categories set up for the item's own catalog (Admin > Categories)
            'category' => ['required', 'string', 'max:100', Rule::exists('categories', 'name')->where('source', $request->input('source'))],
            'retail_group' => ['required', Rule::in(Item::RETAIL_GROUPS)],
        ], [
            'product_code.unique' => 'That product code is already used (it may belong to an archived item).',
            'product_code.required' => 'Enter a product code.',
            'description.required' => 'Enter a description.',
            'description.unique' => 'An item with this description already exists in this catalog (it may be archived).',
            'barcode.required' => 'A barcode is required. Use the arrows in the field to generate one.',
            'barcode.digits' => 'The barcode must be exactly 13 digits.',
            'category.required' => 'Please choose a category.',
            'category.exists' => 'Choose one of the categories listed for this catalog.',
            'barcode.unique' => 'That barcode already belongs to another item.',
        ]);
    }
}

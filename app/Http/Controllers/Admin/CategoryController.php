<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        $counts = Item::whereNotNull('category')
            ->selectRaw('source, category, COUNT(*) as n')
            ->groupBy('source', 'category')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->source.'|'.$r->category => (int) $r->n]);

        $categories = Category::orderBy('name')->get()->map(fn (Category $c) => [
            'id' => $c->id,
            'source' => $c->source,
            'name' => $c->name,
            'items_count' => $counts[$c->source.'|'.$c->name] ?? 0,
        ]);

        return Inertia::render('admin/categories', [
            'categories' => $categories->groupBy('source')->map->values()->union(collect(Item::SOURCES)->mapWithKeys(fn ($s) => [$s => collect()])->all()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        Category::create($data);

        return back()->with('success', "{$data['name']} added to ".$this->catalogName($data['source']).'.');
    }

    /** Renaming also re-files the items under the new name. Past orders keep the name they were placed with. */
    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->validated($request, $category);
        $old = $category->name;

        DB::transaction(function () use ($category, $data, $old) {
            Item::where('source', $category->source)->where('category', $old)->update(['category' => $data['name']]);
            $category->update(['name' => $data['name']]);
        });

        return back()->with('success', $old === $data['name'] ? 'No changes.' : "Renamed {$old} to {$data['name']}.");
    }

    /**
     * Deleting a category that items still use is allowed: those items simply become uncategorized
     * (the admin is warned first). Past orders keep the category name they were placed with.
     */
    public function destroy(Category $category): RedirectResponse
    {
        $moved = DB::transaction(function () use ($category) {
            $moved = Item::where('source', $category->source)->where('category', $category->name)->update(['category' => null]);
            $category->delete();

            return $moved;
        });

        return back()->with('success', $moved > 0
            ? "{$category->name} was deleted. {$moved} ".($moved === 1 ? 'item is' : 'items are').' now uncategorized.'
            : "{$category->name} was deleted.");
    }

    /** The source can't change on rename (that would silently move the category between catalogs). */
    private function validated(Request $request, ?Category $category = null): array
    {
        $request->merge(['name' => strtoupper(trim((string) $request->input('name')))]);
        $source = $category?->source ?? $request->input('source');

        return $request->validate([
            'source' => $category ? ['nullable'] : ['required', Rule::in(Item::SOURCES)],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('categories', 'name')->where('source', $source)->ignore($category),
            ],
        ], ['name.unique' => 'That category already exists in this catalog.']) + ['source' => $source];
    }

    private function catalogName(string $source): string
    {
        return Item::LABELS[$source] ?? $source;
    }
}

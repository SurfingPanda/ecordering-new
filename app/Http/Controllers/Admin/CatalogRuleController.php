<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogRule;
use App\Models\Item;
use App\Services\CatalogRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** "Sync Rules": which catalog the items synced from ECPOS are filed in, editable without touching code. */
class CatalogRuleController extends Controller
{
    public function index(): Response
    {
        $router = new CatalogRouter;
        $misfiled = $router->misfiled();

        // how many of the items from ECPOS each rule applies to (code rules win over group rules, as in the sync)
        $synced = Item::whereNotNull('synced_at')->whereNull('archived_at')->get(['product_code', 'category']);
        $rules = CatalogRule::orderBy('type')->orderBy('match')->get();
        $matches = $rules->mapWithKeys(fn (CatalogRule $r) => [$r->id => $synced->filter(fn ($i) => $r->type === CatalogRule::CODE
            ? str_contains(strtoupper($i->product_code), $r->match)
            : strtoupper(trim((string) $i->category)) === $r->match)->count()]);

        return Inertia::render('admin/sync-rules', [
            'rules' => $rules->map(fn (CatalogRule $r) => ['id' => $r->id, 'type' => $r->type, 'match' => $r->match, 'source' => $r->source, 'items' => $matches[$r->id]]),
            'misfiled' => $misfiled->count(),
            'misfiled_sample' => $misfiled->take(5)->map(fn (Item $i) => ['code' => $i->product_code, 'name' => $i->description, 'from' => $i->source, 'to' => $router->sourceFor($i->product_code, $i->category)])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rule = CatalogRule::create($this->validated($request));

        return back()->with('success', $this->saved($rule, 'added'));
    }

    public function update(Request $request, CatalogRule $rule): RedirectResponse
    {
        $rule->update($this->validated($request, $rule));

        return back()->with('success', $this->saved($rule, 'updated'));
    }

    public function destroy(CatalogRule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('success', 'Rule deleted. Items already filed keep their catalog; new items from ECPOS follow the remaining rules.');
    }

    /** Files the items that came from ECPOS where the rules say, right now (no ECPOS call needed). */
    public function apply(): RedirectResponse
    {
        $moved = (new CatalogRouter)->reapply();

        return back()->with('success', $moved === 0 ? 'Every item is already in the right catalog.' : "{$moved} ".($moved === 1 ? 'item was' : 'items were').' moved to the catalog its rule says.');
    }

    private function validated(Request $request, ?CatalogRule $rule = null): array
    {
        $request->merge(['match' => strtoupper(trim((string) $request->input('match')))]);

        return $request->validate([
            'type' => ['required', Rule::in(CatalogRule::TYPES)],
            'match' => [
                'required', 'string', 'min:2', 'max:100',
                Rule::unique('catalog_rules', 'match')->where('type', $request->input('type'))->ignore($rule),
            ],
            'source' => ['required', Rule::in(Item::SOURCES)],
        ], [
            'match.min' => 'Use at least 2 characters, or the rule would match almost everything.',
            'match.unique' => 'There is already a rule for that.',
        ]);
    }

    private function saved(CatalogRule $rule, string $verb): string
    {
        $count = (new CatalogRouter)->misfiled()->count();

        return "Rule {$verb}. ".($count > 0
            ? "{$count} ".($count === 1 ? 'item is' : 'items are').' not in the catalog the rules say yet: press Move them now, or the next sync will do it.'
            : 'Every item is already where the rules say.');
    }
}

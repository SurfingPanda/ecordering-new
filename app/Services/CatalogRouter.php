<?php

namespace App\Services;

use App\Models\CatalogRule;
use App\Models\Category;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decides which catalog an item that comes from ECPOS belongs to, using the rules admins keep on the "Sync Rules" page.
 *
 *  1. a rule on the item CODE (e.g. contains -SUP-) wins,
 *  2. then a rule on its ECPOS item GROUP (e.g. MERCHANDISE),
 *  3. anything else is a BW Product.
 */
class CatalogRouter
{
    public const DEFAULT = 'bw_products';

    /** @var Collection<int, CatalogRule>|null */
    private ?Collection $rules = null;

    private function rules(): Collection
    {
        return $this->rules ??= CatalogRule::orderBy('id')->get()->groupBy('type');
    }

    public function sourceFor(string $code, ?string $group): string
    {
        $code = strtoupper(trim($code));
        $group = strtoupper(trim((string) $group));

        foreach ($this->rules()->get(CatalogRule::CODE, []) as $rule) {
            if (str_contains($code, $rule->match)) {
                return $rule->source;
            }
        }
        foreach ($this->rules()->get(CatalogRule::GROUP, []) as $rule) {
            if ($group === $rule->match) {
                return $rule->source;
            }
        }

        return self::DEFAULT;
    }

    /** Items that came from ECPOS and sit in a different catalog than the rules say. @return Collection<int, Item> */
    public function misfiled(): Collection
    {
        return Item::whereNotNull('synced_at')->whereNull('archived_at')->get()
            ->filter(fn (Item $i) => $this->sourceFor($i->product_code, $i->category) !== $i->source)
            ->values();
    }

    /** Moves those items where the rules say (items added by hand are never touched). Returns how many moved. */
    public function reapply(): int
    {
        $items = $this->misfiled();

        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                $to = $this->sourceFor($item->product_code, $item->category);
                $item->forceFill(['source' => $to])->save();
                if ($item->category) {
                    Category::firstOrCreate(['source' => $to, 'name' => $item->category]); // the category must exist in the new catalog
                }
            }
        });

        return $items->count();
    }
}

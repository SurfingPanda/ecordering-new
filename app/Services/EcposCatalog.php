<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Keeps the BW Products catalog in step with ECPOS (GET /items). Read-only towards ECPOS: it never sends anything.
 *
 *  - new ECPOS item            -> added to the catalog the Sync Rules say (BW Products when no rule matches); its ECPOS group becomes the category
 *  - changed name/group/type   -> updated
 *  - synced item gone from ECPOS -> archived (never deleted, old orders keep their items); comes back -> restored
 *  - items added by hand (including hand-added Warehouse items) are left alone
 */
class EcposCatalog
{
    public const SOURCE = 'bw_products';

    /** Catalogs the sync maintains (in Warehouse only the items it put there itself). Which one an item goes to comes from the rules on the Sync Rules page (see CatalogRouter). */
    public const SOURCES = ['bw_products', 'merchandise', 'rejects'];

    /** @return list<array{itemid: string, itemname: string, itemgroup: ?string, itemdepartment: ?string, moq: mixed}> */
    public function fetch(): array
    {
        return app(EcposClient::class)->get('items');
    }

    /** ECPOS sends null / 0 / "" for "no minimum"; anything else is rounded up to a whole quantity. */
    private function moq(mixed $value): ?int
    {
        $n = is_numeric($value) ? (int) ceil((float) $value) : 0;

        return $n > 0 ? min($n, 9999) : null;
    }

    /**
     * @param  bool  $dryRun  work out the numbers but write nothing
     * @return array{added: int, updated: int, archived: int, restored: int, unchanged: int, skipped: int, total: int}
     */
    public function sync(bool $dryRun = false, ?array $feed = null): array
    {
        $feed ??= $this->fetch();

        $skipGroups = array_map('strtoupper', config('ecpos.skip_groups'));
        $wanted = [];
        $router = new CatalogRouter;
        $skipped = 0;
        foreach ($feed as $row) {
            $code = trim((string) ($row['itemid'] ?? ''));
            $name = trim((string) ($row['itemname'] ?? ''));
            if ($code === '' || $name === '' || in_array(strtoupper(trim((string) ($row['itemgroup'] ?? ''))), $skipGroups, true)) {
                $skipped++;

                continue;
            }
            $wanted[$code] = [
                'source' => $router->sourceFor($code, (string) ($row['itemgroup'] ?? '')),
                'description' => mb_substr($name, 0, 255),
                'category' => mb_substr(trim((string) ($row['itemgroup'] ?? '')) ?: 'UNCATEGORISED', 0, 100),
                'retail_group' => strtoupper(trim((string) ($row['itemdepartment'] ?? ''))) === 'REGULAR PRODUCT' ? 'regular_product' : 'non_product',
                'moq' => $this->moq($row['moq'] ?? null),
            ];
        }

        // Never wipe the catalog because of an empty / broken answer.
        if ($wanted === []) {
            throw new RuntimeException('ECPOS returned no usable items, so nothing was changed.');
        }

        $stats = ['added' => 0, 'updated' => 0, 'archived' => 0, 'restored' => 0, 'unchanged' => 0, 'skipped' => $skipped, 'total' => count($wanted)];

        $run = function () use ($wanted, &$stats, $dryRun) {
            $existing = Item::where(fn ($q) => $q->whereIn('source', self::SOURCES)->orWhere(fn ($w) => $w->where('source', 'warehouse')->whereNotNull('synced_at')))->get()->keyBy('product_code');
            $now = now();

            foreach ($wanted as $code => $data) {
                $item = $existing->get($code);

                if (! $item) {
                    $stats['added']++;
                    if (! $dryRun) {
                        $new = new Item(['product_code' => $code] + $data);
                        $new->forceFill(['synced_at' => $now])->save();
                    }

                    continue;
                }

                $changed = $item->source !== $data['source'] || $item->description !== $data['description'] || $item->category !== $data['category'] || $item->retail_group !== $data['retail_group'] || $item->moq !== $data['moq'];
                $restore = $item->archived_at !== null && $item->synced_at !== null; // only bring back what the sync itself archived
                $adopt = $item->synced_at === null;

                if ($changed || $restore || $adopt) {
                    $changed || $adopt ? $stats['updated']++ : null;
                    $restore ? $stats['restored']++ : null;
                    if (! $dryRun) {
                        $item->fill($data);
                        $item->forceFill(['synced_at' => $now] + ($restore ? ['archived_at' => null] : []))->save();
                    }
                } else {
                    $stats['unchanged']++;
                    if (! $dryRun) {
                        $item->forceFill(['synced_at' => $now])->save();
                    }
                }
            }

            // synced items ECPOS no longer lists: archive them (never delete)
            $gone = $existing->filter(fn (Item $i) => $i->synced_at !== null && $i->archived_at === null && ! isset($wanted[$i->product_code]));
            $stats['archived'] = $gone->count();
            if (! $dryRun && $gone->isNotEmpty()) {
                Item::whereKey($gone->modelKeys())->update(['archived_at' => $now]);
            }

            // every group becomes a BW Product category, so the items always pass the "category must exist" rule
            if (! $dryRun) {
                foreach (collect($wanted)->unique(fn ($w) => $w['source'].'|'.$w['category']) as $w) {
                    Category::firstOrCreate(['source' => $w['source'], 'name' => $w['category']]);
                }
            }
        };

        $dryRun ? $run() : DB::transaction($run);

        if (! $dryRun) {
            AppSetting::write('ecpos_last_sync', now()->toIso8601String());
            AppSetting::write('ecpos_last_sync_result', json_encode($stats));
        }

        return $stats;
    }
}

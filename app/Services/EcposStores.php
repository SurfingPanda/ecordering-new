<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Brings the ECPOS store list (GET /stores) into our stores. Read-only towards ECPOS, and it never changes a store
 * you already have except to record its ECPOS store ID:
 *  - an existing store whose name matches an ECPOS store (ignoring "BW", spaces and dashes) is linked to it
 *  - every other ECPOS store is added (code = its ECPOS store ID, no login yet)
 *  - ECPOS demo/test stores (store IDs starting with "C") are skipped
 */
class EcposStores
{
    /** @return list<array{STOREID: string, NAME: string, ADDRESS: ?string, PHONE: ?string}> */
    public function fetch(): array
    {
        return app(EcposClient::class)->get('stores');
    }

    private function key(string $name): string
    {
        return preg_replace('/[^A-Z0-9]/', '', preg_replace('/^BW\s+/', '', strtoupper(trim($name))));
    }

    /** "SAN-CARLOS-TC" -> "San Carlos TC": title case, but known abbreviations stay in capitals. */
    private function displayName(string $name): string
    {
        $acronyms = ['WM', 'RCS', 'SMG', 'MH', 'TC', 'ROB', 'RSO'];

        return collect(preg_split('/[\s-]+/', trim($name)))
            ->map(fn ($w) => in_array(strtoupper($w), $acronyms, true) ? strtoupper($w) : Str::title(mb_strtolower($w)))
            ->implode(' ');
    }

    /** @return array{added: int, linked: int, unchanged: int, skipped: int, total: int} */
    public function sync(bool $dryRun = false, ?array $feed = null): array
    {
        $feed ??= $this->fetch();
        $feed = array_values(array_filter($feed, fn ($r) => is_array($r) && trim((string) ($r['STOREID'] ?? '')) !== '' && trim((string) ($r['NAME'] ?? '')) !== ''));

        if ($feed === []) {
            throw new RuntimeException('ECPOS returned no stores, so nothing was changed.');
        }

        $stats = ['added' => 0, 'linked' => 0, 'unchanged' => 0, 'skipped' => 0, 'total' => count($feed)];

        $run = function () use ($feed, &$stats, $dryRun) {
            $stores = Store::all();
            $byEcpos = $stores->whereNotNull('ecpos_store_id')->keyBy('ecpos_store_id');
            $unlinked = $stores->whereNull('ecpos_store_id');
            $names = $stores->pluck('name')->map(fn ($n) => strtoupper($n))->all();
            $codes = $stores->pluck('code')->map(fn ($c) => strtoupper($c))->all();

            foreach ($feed as $row) {
                $id = trim((string) $row['STOREID']);
                $name = trim((string) $row['NAME']);

                if ($byEcpos->has($id)) {
                    $stats['unchanged']++;

                    continue;
                }
                if (str_starts_with(strtoupper($id), 'C')) { // demo / test stores
                    $stats['skipped']++;

                    continue;
                }

                // an existing, not-yet-linked store with the same name -> link it
                $match = $unlinked->first(fn (Store $s) => $this->key($s->name) === $this->key($name));
                if ($match) {
                    $stats['linked']++;
                    if (! $dryRun) {
                        $match->forceFill(['ecpos_store_id' => $id])->save();
                    }
                    $unlinked = $unlinked->reject(fn (Store $s) => $s->id === $match->id);

                    continue;
                }

                $newName = 'BW '.$this->displayName($name);
                if (in_array(strtoupper($newName), $names, true) || in_array(strtoupper($id), $codes, true)) {
                    $stats['skipped']++; // would clash with a store you already have

                    continue;
                }

                $stats['added']++;
                $names[] = strtoupper($newName);
                $codes[] = strtoupper($id);
                if (! $dryRun) {
                    $address = trim((string) ($row['ADDRESS'] ?? ''));
                    $phone = trim((string) ($row['PHONE'] ?? ''));
                    $store = new Store([
                        'code' => strtoupper($id),
                        'name' => $newName,
                        'address' => in_array(strtoupper($address), ['', 'N/A'], true) ? null : $address,
                        'contact_number' => preg_match('/^[0-9+()\s.-]{5,40}$/', $phone) === 1 ? $phone : null,
                        'is_active' => true,
                    ]);
                    $store->forceFill(['ecpos_store_id' => $id])->save();
                }
            }
        };

        $dryRun ? $run() : DB::transaction($run);

        if (! $dryRun) {
            AppSetting::write('ecpos_stores_last_sync', now()->toIso8601String());
        }

        return $stats;
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Who works at a store (GET /staff?store=), for the "who is placing this order" picker. */
class EcposStaff
{
    /** Seconds to wait for ECPOS when looking up a store's staff. */
    private const LOOKUP_TIMEOUT = 3;

    /**
     * Staff names for an ECPOS store, cached briefly. Never throws: if ECPOS can't be reached the list is empty and
     * the order simply goes without a staff pick (the picker is a nicety, it must never block ordering).
     *
     * @return list<string>
     */
    public function forStore(?string $ecposStoreId): array
    {
        if (! $ecposStoreId || ! config('ecpos.api_key')) {
            return [];
        }

        $key = 'ecpos-staff:'.$ecposStoreId;
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        // The order page waits on this, so give ECPOS a few seconds, not the full send timeout. When it can't be reached the
        // page loads without the list, and we try again in a minute instead of remembering "no staff" for ten.
        try {
            $rows = app(EcposClient::class)->get('staff?store='.rawurlencode($ecposStoreId), timeout: self::LOOKUP_TIMEOUT, attempts: 1);
        } catch (RuntimeException) {
            Cache::put($key, [], now()->addMinute());

            return [];
        }

        $names = collect($rows)->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->unique()->sort()->values()->all();
        Cache::put($key, $names, now()->addMinutes(10));

        return $names;
    }
}

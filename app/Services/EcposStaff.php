<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Who works at a store (GET /staff?store=), for the "who is placing this order" picker. */
class EcposStaff
{
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

        return Cache::remember('ecpos-staff:'.$ecposStoreId, now()->addMinutes(10), function () use ($ecposStoreId) {
            try {
                $rows = app(EcposClient::class)->get('staff?store='.rawurlencode($ecposStoreId));
            } catch (RuntimeException) {
                return [];
            }

            return collect($rows)->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->unique()->sort()->values()->all();
        });
    }
}

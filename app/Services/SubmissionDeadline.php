<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Store;
use App\Models\StoreDeadline;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Works out when a store's order submission closes.
 *
 * Priority (first match wins):
 *   1. a deadline set for that store on that exact calendar day,
 *   2. the store's recurring deadline,
 *   3. the system-wide default (stored in app_settings, never hard-coded).
 *
 * "Day" always means the calendar day in the application's configured business timezone (APP_TIMEZONE).
 */
class SubmissionDeadline
{
    public const DEFAULT_FALLBACK = '16:00'; // only used if the settings row is missing entirely

    public function defaultTime(): string
    {
        return AppSetting::read('default_deadline', self::DEFAULT_FALLBACK);
    }

    /**
     * @return array{time: string, source: 'date'|'store'|'default', at: CarbonImmutable, date: string}
     */
    public function for(Store $store, ?CarbonInterface $day = null): array
    {
        $day = ($day ? CarbonImmutable::instance($day) : CarbonImmutable::now())->startOfDay();
        $date = $day->toDateString();

        $override = StoreDeadline::where('store_id', $store->id)->whereDate('date', $date)->first();
        $recurring = $override ? null : StoreDeadline::where('store_id', $store->id)->whereNull('date')->first();

        [$time, $source] = match (true) {
            $override !== null => [$override->time, 'date'],
            $recurring !== null => [$recurring->time, 'store'],
            default => [$this->defaultTime(), 'default'],
        };

        [$h, $m] = array_map('intval', explode(':', $time));

        return [
            'time' => $time,
            'source' => $source,
            'date' => $date,
            'at' => $day->setTime($h, $m),
        ];
    }

    /** True while the store may still submit today. */
    public function isOpen(Store $store, ?CarbonInterface $now = null): bool
    {
        $now = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now();

        return $now->lessThanOrEqualTo($this->for($store, $now)['at']);
    }
}

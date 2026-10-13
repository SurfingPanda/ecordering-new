<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The scheduler stamps this every minute (routes/console.php). Orders only reach ECPOS while the scheduler (or a queue
 * worker) runs, so a stale stamp is the early warning that orders are piling up unsent.
 */
class SchedulerHeartbeat
{
    private const KEY = 'scheduler:heartbeat';

    /** Minutes without a stamp before we say the scheduler is not running. */
    private const STALE_AFTER = 5;

    public static function beat(): void
    {
        Cache::forever(self::KEY, now()->getTimestamp());
    }

    public static function lastBeat(): ?int
    {
        try {
            return Cache::get(self::KEY);
        } catch (Throwable) {
            return null;
        }
    }

    /** Only matters while orders are sent to ECPOS; with sending off nothing waits on the scheduler. */
    public static function isStale(): bool
    {
        if (! config('ecpos.send_orders')) {
            return false;
        }

        $last = self::lastBeat();

        return $last === null || $last < now()->subMinutes(self::STALE_AFTER)->getTimestamp();
    }
}

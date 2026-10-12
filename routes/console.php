<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;

// keep BW Products in step with ECPOS every night (needs the Laravel scheduler running: php artisan schedule:work, or a cron job)
Schedule::command('ecpos:sync-items')->dailyAt('02:00')->withoutOverlapping()->onOneServer();

// send queued orders to ECPOS every minute (so one cron line / `schedule:work` is enough; a permanent `queue:work` also works)
Schedule::command('queue:work --stop-when-empty --tries=1 --max-time=55')->everyMinute()->withoutOverlapping(2);

// the end-of-day "who ordered, who didn't" email for area managers and admins (time: NOTIFY_SUMMARY_TIME)
Schedule::command('orders:daily-summary')->dailyAt(config('notifications.summary_time'))->withoutOverlapping()->onOneServer();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

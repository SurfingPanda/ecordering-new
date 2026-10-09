<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;

// keep BW Products in step with ECPOS every night (needs the Laravel scheduler running: php artisan schedule:work, or a cron job)
Schedule::command('ecpos:sync-items')->dailyAt('02:00')->withoutOverlapping()->onOneServer();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

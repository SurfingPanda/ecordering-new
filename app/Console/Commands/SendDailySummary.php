<?php

namespace App\Console\Commands;

use App\Mail\DailySummaryMail;
use App\Models\User;
use App\Services\DailySummary;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the end-of-day summary: each area manager gets one for the stores they handle, and the admins on
 * NOTIFY_ADMIN_EMAILS get one for every store. Runs every day at NOTIFY_SUMMARY_TIME (see routes/console.php).
 */
class SendDailySummary extends Command
{
    protected $signature = 'orders:daily-summary
                            {--date= : The day to summarise (YYYY-MM-DD), default today}
                            {--only= : Send only to this email address (for trying it out)}
                            {--force : Send even if the daily summary is switched off}';

    protected $description = 'Email each area manager (and the admins) who ordered and who did not today';

    public function handle(DailySummary $summaries): int
    {
        if (! config('notifications.daily_summary') && ! $this->option('force')) {
            $this->info('The daily summary is switched off (NOTIFY_DAILY_SUMMARY=false).');

            return self::SUCCESS;
        }

        $raw = (string) $this->option('date');
        if ($raw !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1 || strtotime($raw) === false)) {
            $this->error('Use a date like 2026-10-12.');

            return self::FAILURE;
        }
        $day = $raw !== '' ? CarbonImmutable::parse($raw) : CarbonImmutable::today();
        $only = strtolower(trim((string) $this->option('only')));

        // who gets one: [email, name, the stores (null = all), what to call the scope]
        $to = [];
        foreach (User::where('role', User::AREA_MANAGER)->with('managedStores:id')->orderBy('name')->get() as $m) {
            $ids = $m->managedStores->pluck('id')->map(fn ($i) => (int) $i)->all();
            if ($ids !== []) { // a manager without stores has nothing to summarise
                $to[strtolower($m->email)] = [$m->email, $m->name, $ids, count($ids).' '.(count($ids) === 1 ? 'store' : 'stores').' you handle'];
            }
        }
        if (config('notifications.daily_summary_admins')) {
            foreach (config('notifications.admin_emails') as $email) {
                $to[strtolower($email)] ??= [$email, 'there', null, 'all stores'];
            }
        }

        $sent = 0;
        foreach ($to as $key => [$email, $name, $ids, $scope]) {
            if ($only !== '' && $key !== $only) {
                continue;
            }

            try {
                Mail::to($email)->send(new DailySummaryMail($name, $scope, $summaries->build($ids, $day)));
                $sent++;
                $this->line("Summary for {$day->toDateString()} to {$email} ({$scope})");
            } catch (Throwable $e) {
                report($e);
                $this->warn("Could not send to {$email}: ".$e->getMessage());
            }
        }

        $this->info($sent === 0 ? 'Nobody to send a summary to.' : "{$sent} ".($sent === 1 ? 'summary' : 'summaries').' sent.');

        return self::SUCCESS;
    }
}

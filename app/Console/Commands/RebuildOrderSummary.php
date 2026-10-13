<?php

namespace App\Console\Commands;

use App\Services\OrderSummary;
use Illuminate\Console\Command;

class RebuildOrderSummary extends Command
{
    protected $signature = 'orders:rebuild-summary {--days= : only rebuild orders dated in the last N days}';

    protected $description = 'Rebuild the daily item totals the dashboard and trends read from (they are kept up to date automatically; this is the safety net)';

    public function handle(): int
    {
        $days = $this->option('days') ? (int) $this->option('days') : null;

        $rows = OrderSummary::rebuild($days);
        $this->info("Rebuilt the daily item totals{$this->scope($days)}: {$rows} rows.");

        return self::SUCCESS;
    }

    private function scope(?int $days): string
    {
        return $days ? " for the last {$days} days" : '';
    }
}

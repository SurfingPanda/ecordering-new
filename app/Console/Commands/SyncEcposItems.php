<?php

namespace App\Console\Commands;

use App\Services\EcposCatalog;
use Illuminate\Console\Command;
use RuntimeException;

class SyncEcposItems extends Command
{
    protected $signature = 'ecpos:sync-items {--dry-run : Show what would change without writing anything}';

    protected $description = 'Bring the BW Products catalog in line with ECPOS (read-only towards ECPOS)';

    public function handle(EcposCatalog $catalog): int
    {
        try {
            $stats = $catalog->sync((bool) $this->option('dry-run'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? '[dry run] ' : '').'ECPOS items: '.$stats['total']);
        $this->table(['added', 'updated', 'restored', 'archived', 'unchanged', 'skipped'], [[$stats['added'], $stats['updated'], $stats['restored'], $stats['archived'], $stats['unchanged'], $stats['skipped']]]);

        return self::SUCCESS;
    }
}

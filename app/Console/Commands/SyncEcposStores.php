<?php

namespace App\Console\Commands;

use App\Services\EcposStores;
use Illuminate\Console\Command;
use RuntimeException;

class SyncEcposStores extends Command
{
    protected $signature = 'ecpos:sync-stores {--dry-run : Show what would change without writing anything}';

    protected $description = 'Link our stores to ECPOS stores and add the ones we do not have yet (read-only towards ECPOS)';

    public function handle(EcposStores $stores): int
    {
        try {
            $s = $stores->sync((bool) $this->option('dry-run'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? '[dry run] ' : '').'ECPOS stores: '.$s['total']);
        $this->table(['added', 'linked', 'unchanged', 'skipped'], [[$s['added'], $s['linked'], $s['unchanged'], $s['skipped']]]);

        return self::SUCCESS;
    }
}

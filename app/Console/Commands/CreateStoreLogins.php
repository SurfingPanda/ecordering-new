<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateStoreLogins extends Command
{
    protected $signature = 'stores:create-logins
        {--password=welcome : The initial password for every new login}
        {--domain=ecticketph.com : Email domain, the address is bw.<storename>@<domain>}
        {--dry-run : List the logins that would be created without creating them}';

    protected $description = 'Create a login for every store that has none yet (existing logins are never touched)';

    public function handle(): int
    {
        $password = (string) $this->option('password');
        $domain = ltrim((string) $this->option('domain'), '@');
        $rows = [];

        $create = function () use ($password, $domain, &$rows) {
            foreach (Store::doesntHave('users')->orderBy('name')->get() as $store) {
                // "BW WM Antipolo" -> bw.wmantipolo
                $slug = 'bw.'.Str::of($store->name)->replaceMatches('/^BW\s+/i', '')->lower()->replaceMatches('/[^a-z0-9]/', '');
                $login = $slug;
                for ($n = 2; User::where('email', "{$login}@{$domain}")->exists() || in_array($login, array_column($rows, 'name'), true); $n++) {
                    $login = $slug.$n;
                }

                if (! $this->option('dry-run')) {
                    $user = new User(['name' => $login, 'email' => "{$login}@{$domain}", 'password' => $password]); // 'hashed' cast
                    $user->forceFill(['role' => User::STORE, 'store_id' => $store->id])->save();
                }
                $rows[] = ['store' => $store->name, 'name' => $login, 'email' => "{$login}@{$domain}"];
            }
        };

        $this->option('dry-run') ? $create() : DB::transaction($create);

        if ($rows === []) {
            $this->info('Every store already has a login.');

            return self::SUCCESS;
        }

        $this->table(['Store', 'Login name', 'Email (sign in with this)'], $rows);
        $this->info(($this->option('dry-run') ? '[dry run] would create ' : 'Created ').count($rows).' logins.');

        if (! $this->option('dry-run')) {
            $csv = storage_path('app/store-logins.csv');
            $fh = fopen($csv, 'w');
            fputcsv($fh, ['Store', 'Email', 'Password']);
            foreach ($rows as $r) {
                fputcsv($fh, [$r['store'], $r['email'], $password]);
            }
            fclose($fh);
            $this->line("List saved to {$csv}");
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateOpicLogin extends Command
{
    protected $signature = 'opic:create
        {email : Sign-in email for the OPIC account}
        {--name=OPIC : Display name}
        {--password= : Password (a random one is generated and shown when left out)}';

    protected $description = 'Create the OPIC login, or reset its password if the email already belongs to an OPIC account';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $password = (string) ($this->option('password') ?: Str::password(12, symbols: false));

        if (strlen($password) < 8) {
            $this->error('The password must be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if ($user && ! $user->isOpic()) {
            $this->error("{$email} already belongs to a different kind of account.");

            return self::FAILURE;
        }

        $user ??= new User(['email' => $email]);
        $user->fill(['name' => (string) $this->option('name'), 'password' => $password]); // 'hashed' cast
        $user->forceFill(['role' => User::OPIC, 'store_id' => null, 'deactivated_at' => null, 'remember_token' => Str::random(60)])->save(); // role is never mass-assignable

        $this->table(['Email (sign in with this)', 'Password'], [[$email, $password]]);
        $this->info('OPIC account is ready.');

        return self::SUCCESS;
    }
}

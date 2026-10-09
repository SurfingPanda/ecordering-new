<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin account (role is set explicitly: it is not mass-assignable).
        $admin = User::firstOrNew(['email' => 'staff@bwbakeshop.test']);
        $admin->fill(['name' => 'Admin', 'password' => 'password'])->forceFill(['role' => User::ADMIN, 'store_id' => null])->save();

        // Sample stores, each with its own login. Sample passwords only - change them for real use.
        $stores = [
            ['SF01', 'BW San Fernando', 'sanfernando@bwbakeshop.test', 'San Fernando Store'],
            ['AN01', 'BW Angeles', 'angeles@bwbakeshop.test', 'Angeles Store'],
            ['MB01', 'BW Mabalacat', 'mabalacat@bwbakeshop.test', 'Mabalacat Store'],
        ];

        foreach ($stores as [$code, $name, $email, $userName]) {
            $store = Store::updateOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
            $user = User::firstOrNew(['email' => $email]);
            $user->fill(['name' => $userName, 'password' => 'password'])->forceFill(['role' => User::STORE, 'store_id' => $store->id])->save();
        }

        // No sample items: the catalog comes from ECPOS (php artisan ecpos:sync-items, or Product Catalog > Sync from ECPOS).
    }
}

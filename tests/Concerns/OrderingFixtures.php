<?php

namespace Tests\Concerns;

use App\Models\Item;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;

/** Small builders shared by the feature tests. */
trait OrderingFixtures
{
    /** Freeze the clock at a business-timezone time, e.g. at('2026-10-09 10:00'). */
    protected function at(string $dateTime): Carbon
    {
        $now = Carbon::parse($dateTime, config('app.timezone'));
        Carbon::setTestNow($now);

        return $now;
    }

    protected function makeAdmin(string $email = 'admin@test.test'): User
    {
        $u = new User(['name' => 'Admin', 'email' => $email, 'password' => 'secret-pass']);
        $u->forceFill(['role' => User::ADMIN])->save();

        return $u;
    }

    protected function makeStore(string $code = 'SF01', bool $active = true, ?string $name = null): Store
    {
        return Store::create(['code' => $code, 'name' => $name ?? "Store $code", 'is_active' => $active]);
    }

    protected function makeStoreUser(Store $store, ?string $email = null): User
    {
        $u = new User(['name' => "User {$store->code}", 'email' => $email ?? strtolower($store->code).'@test.test', 'password' => 'secret-pass']);
        $u->forceFill(['role' => User::STORE, 'store_id' => $store->id])->save();

        return $u;
    }

    /** @return array{bw: Item, bw2: Item, wh: Item, wh2: Item} */
    protected function makeItems(): array
    {
        $mk = fn (string $code, string $name, string $source, string $cat) => Item::create([
            'product_code' => $code, 'description' => $name, 'source' => $source, 'category' => $cat, 'retail_group' => $source === 'warehouse' ? 'non_product' : 'regular_product',
        ]);

        return [
            'bw' => $mk('BW001', 'Ube Cake', 'bw_products', 'CAKES'),
            'bw2' => $mk('BW002', 'Pandesal', 'bw_products', 'BREADS'),
            'wh' => $mk('WH001', 'Floor Cleaner', 'warehouse', 'CLEANING'),
            'wh2' => $mk('WH002', 'Cake Topper', 'warehouse', 'TOPPERS'),
        ];
    }

    /** Inserts an order directly (bypassing the HTTP rules) so a test can set up any state. */
    protected function seedOrder(Store $store, User $user, string $status = 'posted', ?string $date = null, array $lines = []): Order
    {
        $order = Order::create([
            'user_id' => $user->id, 'store_id' => $store->id, 'status' => $status,
            'order_date' => $date ?? now()->toDateString(),
            'posted_at' => $status === 'posted' ? now() : null,
        ]);

        foreach ($lines as [$item, $qty]) {
            $order->lines()->create([
                'item_id' => $item->id, 'product_code' => $item->product_code, 'name' => $item->description,
                'source' => $item->source, 'category' => $item->category, 'quantity' => $qty,
            ]);
        }

        return $order;
    }

    /** Payload for POST /orders. */
    protected function payload(array $lines, ?string $date = null, array $extra = []): array
    {
        return [
            'order_date' => $date ?? now()->toDateString(),
            'lines' => collect($lines)->map(fn ($l) => ['item_id' => $l[0]->id ?? $l[0], 'quantity' => $l[1]])->all(),
            ...$extra,
        ];
    }
}

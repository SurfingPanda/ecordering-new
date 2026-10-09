<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Optional sample orders so the dashboard and consolidated reports have something to show.
 * Run with: php artisan db:seed --class=DemoOrdersSeeder
 * (php artisan migrate:fresh --seed removes them again.)
 *
 * One order per store per day, posted before the 4:00 PM cutoff; a few cancelled TRs and a few pending ones.
 */
class DemoOrdersSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', User::ADMIN)->first();
        $stores = Store::where('is_active', true)->with('users')->get();
        $items = Item::all();
        if (! $admin || $stores->isEmpty() || $items->isEmpty()) {
            return;
        }

        mt_srand(7); // same sample data every run

        // popularity weights so some items clearly sell more than others
        $weights = $items->mapWithKeys(fn (Item $i) => [$i->id => mt_rand(1, 10) ** 2]);

        for ($daysAgo = 59; $daysAgo >= 1; $daysAgo--) {
            foreach ($stores as $store) {
                if (mt_rand(1, 100) > 75) {
                    continue; // not every store orders every day
                }

                $placed = now()->subDays($daysAgo)->setTime(mt_rand(7, 13), mt_rand(0, 59));
                $order = $this->makeOrder($store, $store->users->first(), $placed, $items, $weights, 'posted');

                if (mt_rand(1, 100) <= 4) {
                    // an admin cancelled this posted TR
                    $order->update([
                        'status' => 'cancelled', 'cancelled_at' => $placed->copy()->addHours(2), 'cancelled_by' => $admin->id,
                        'cancellation_reason' => 'Wrong quantities entered by the store.',
                    ]);
                    $order->record('cancelled', $admin, 'Wrong quantities entered by the store.');
                }
            }
        }

        // today: the first store has a pending order, the second has already posted
        $today = now()->setTime(8, 30);
        $this->makeOrder($stores[0], $stores[0]->users->first(), $today, $items, $weights, 'pending');
        if ($stores->count() > 1) {
            $this->makeOrder($stores[1], $stores[1]->users->first(), $today->copy()->addMinutes(20), $items, $weights, 'posted');
        }
    }

    private function makeOrder(Store $store, User $user, $placed, $items, $weights, string $status): Order
    {
        $order = Order::create([
            'user_id' => $user->id, 'store_id' => $store->id, 'status' => $status,
            'order_date' => $placed->toDateString(),
            'posted_at' => $status === 'posted' ? $placed->copy()->addMinutes(mt_rand(5, 40)) : null,
        ]);
        $order->forceFill(['created_at' => $placed, 'updated_at' => $placed])->save();

        $picked = [];
        for ($k = mt_rand(3, 8); $k > 0; $k--) {
            $picked[$this->weighted($weights)] = true;
        }
        foreach (array_keys($picked) as $id) {
            $item = $items->firstWhere('id', $id);
            $qty = $item->source === 'warehouse' ? mt_rand(1, 6) : mt_rand(4, 40);
            $order->lines()->create([
                'item_id' => $item->id, 'product_code' => $item->product_code, 'name' => $item->description,
                'source' => $item->source, 'category' => $item->category, 'quantity' => $qty,
            ]);
        }

        $order->events()->create(['user_id' => $user->id, 'event' => 'placed', 'created_at' => $placed]);
        if ($status === 'posted') {
            $order->events()->create(['user_id' => $user->id, 'event' => 'posted', 'created_at' => $order->posted_at]);
        }

        return $order;
    }

    private function weighted($weights): int
    {
        $roll = mt_rand(1, $weights->sum());
        foreach ($weights as $id => $w) {
            if (($roll -= $w) <= 0) {
                return $id;
            }
        }

        return $weights->keys()->last();
    }
}

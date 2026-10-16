<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class OpicTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private User $opic;

    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $items = $this->makeItems();
        foreach (['A1', 'B1'] as $code) {
            $store = $this->makeStore($code, true, "Store $code");
            $user = $this->makeStoreUser($store, strtolower($code).'@store.test');
            $this->orders[$code] = $this->seedOrder($store, $user, 'posted', now()->toDateString(), [[$items['bw'], 5]]);
        }
        $this->makeStore('C1', true, 'Store C1'); // has not ordered

        $this->opic = (new User(['name' => 'OPIC', 'email' => 'opic@bw.test', 'password' => 'secret-pass']));
        $this->opic->forceFill(['role' => User::OPIC])->save();
    }

    public function test_opic_sees_every_stores_orders_and_who_has_not_ordered(): void
    {
        $this->actingAs($this->opic)->get('/orders')->assertOk()->assertInertia(fn ($p) => $p->where('orders.total', 2));
        $this->actingAs($this->opic)->get('/admin/today')->assertOk()->assertInertia(fn ($p) => $p->where('summary.waiting', 1));
        $this->actingAs($this->opic)->get('/retails?source=rejects')->assertOk();
        $this->actingAs($this->opic)->get('/forecast')->assertOk()->assertInertia(fn ($p) => $p->where('all_stores', true));
    }

    public function test_opic_can_cancel_a_posted_tr_but_not_use_ecpos_or_order(): void
    {
        $order = $this->orders['A1'];
        $this->actingAs($this->opic)->post("/orders/{$order->id}/cancel", ['reason' => 'Duplicate order'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->fresh()->status);

        $this->actingAs($this->opic)->get('/admin/ecpos-queue')->assertForbidden();
        $this->actingAs($this->opic)->post("/orders/{$this->orders['B1']->id}/ecpos-send")->assertForbidden();
        $this->actingAs($this->opic)->get('/orders/create')->assertForbidden();
        $this->actingAs($this->opic)->get('/admin/stores')->assertForbidden();
    }
}

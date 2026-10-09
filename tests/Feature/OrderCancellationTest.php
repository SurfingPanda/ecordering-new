<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    public function test_admin_can_cancel_a_posted_tr_with_a_reason(): void
    {
        $admin = $this->makeAdmin();
        $this->at('2026-10-09 11:00');
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();
        $order = $this->seedOrder($store, $user, 'posted', null, [[$items['bw'], 7], [$items['wh'], 2]]);

        $this->actingAs($admin)->post("/orders/{$order->id}/cancel", ['reason' => 'Wrong quantities'])
            ->assertRedirect()->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame($admin->id, $order->cancelled_by);
        $this->assertSame('Wrong quantities', $order->cancellation_reason);
        $this->assertSame(now()->timestamp, $order->cancelled_at->timestamp);

        // nothing is deleted
        $this->assertSame(2, $order->lines()->count());
        $this->assertDatabaseHas('orders', ['id' => $order->id]);

        // audit trail
        $event = $order->events()->where('event', 'cancelled')->firstOrFail();
        $this->assertSame($admin->id, $event->user_id);
        $this->assertSame('Wrong quantities', $event->note);
    }

    public function test_a_reason_is_required(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();
        $order = $this->seedOrder($store, $this->makeStoreUser($store), 'posted');

        foreach ([[], ['reason' => ''], ['reason' => '  '], ['reason' => 'ab'], ['reason' => str_repeat('x', 501)]] as $body) {
            $this->actingAs($admin)->post("/orders/{$order->id}/cancel", $body)->assertSessionHasErrors('reason');
        }
        $this->assertSame('posted', $order->fresh()->status);
        $this->assertNull($order->fresh()->cancelled_at);
    }

    public function test_only_posted_trs_can_be_cancelled_by_an_admin(): void
    {
        $admin = $this->makeAdmin();
        $s = $this->makeStore();
        $u = $this->makeStoreUser($s);
        $pending = $this->seedOrder($s, $u, 'pending', '2026-10-01');

        $this->actingAs($admin)->post("/orders/{$pending->id}/cancel", ['reason' => 'not allowed yet'])->assertSessionHasErrors('reason');
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_a_tr_cannot_be_cancelled_twice(): void
    {
        $admin = $this->makeAdmin();
        $s = $this->makeStore();
        $order = $this->seedOrder($s, $this->makeStoreUser($s), 'posted');

        $this->actingAs($admin)->post("/orders/{$order->id}/cancel", ['reason' => 'first time'])->assertSessionHasNoErrors();
        $first = $order->fresh();

        $this->actingAs($admin)->post("/orders/{$order->id}/cancel", ['reason' => 'second time'])->assertSessionHasErrors('reason');

        $order->refresh();
        $this->assertSame('first time', $order->cancellation_reason, 'the original reason is not overwritten');
        $this->assertEquals($first->cancelled_at, $order->cancelled_at);
        $this->assertSame(1, $order->events()->where('event', 'cancelled')->count());
    }

    public function test_cancelling_frees_the_store_to_order_again_for_that_date(): void
    {
        $this->at('2026-10-09 10:00');
        $admin = $this->makeAdmin();
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();

        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 5]]));
        $tr = Order::where('status', 'pending')->firstOrFail();
        $this->actingAs($user)->post("/orders/{$tr->id}/post");

        // posted: blocked from ordering that day again
        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 8]]))->assertSessionHasErrors('order_date');

        // the admin cancels the posted TR...
        $this->actingAs($admin)->post("/orders/{$tr->id}/cancel", ['reason' => 'Redo please'])->assertSessionHasNoErrors();

        // ...so the store can order again, and the original TR is still there as CANCELLED
        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 8]]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('cancelled', $tr->fresh()->status);
        $this->assertSame(1, Order::where('status', 'pending')->count());
        $this->assertNotSame($tr->id, Order::where('status', 'pending')->first()->id, 'a new TR number is issued');
    }

    public function test_cancelled_orders_stay_visible_to_the_admin_and_the_store(): void
    {
        $admin = $this->makeAdmin();
        $s = $this->makeStore();
        $u = $this->makeStoreUser($s);
        $order = $this->seedOrder($s, $u, 'posted');
        $this->actingAs($admin)->post("/orders/{$order->id}/cancel", ['reason' => 'because'])->assertSessionHasNoErrors();

        foreach ([$admin, $u] as $viewer) {
            $rows = $this->actingAs($viewer)->get('/orders?status=cancelled')->viewData('page')['props']['orders']['data'];
            $this->assertSame([$order->id], collect($rows)->pluck('id')->all());
        }

        $detail = $this->actingAs($u)->get("/orders/{$order->id}")->viewData('page')['props']['order'];
        $this->assertSame('cancelled', $detail['status']);
        $this->assertSame($admin->name, $detail['cancelled_by']);
        $this->assertSame('because', $detail['cancellation_reason']);
    }

    public function test_cancelled_orders_are_left_out_of_reports(): void
    {
        $admin = $this->makeAdmin();
        $this->at('2026-10-09 10:00');
        $s = $this->makeStore();
        $u = $this->makeStoreUser($s);
        $items = $this->makeItems();
        $keep = $this->seedOrder($s, $u, 'posted', '2026-10-09', [[$items['bw'], 10]]);
        $drop = $this->seedOrder($s, $u, 'posted', '2026-10-08', [[$items['bw'], 90]]);
        $this->actingAs($admin)->post("/orders/{$drop->id}/cancel", ['reason' => 'void'])->assertSessionHasNoErrors();

        $units = $this->actingAs($admin)->get('/dashboard')->viewData('page')['props']['kpis']['units']['value'];
        $this->assertSame(10, $units);
        $this->assertNotNull($keep);
    }
}

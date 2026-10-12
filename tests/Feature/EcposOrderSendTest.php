<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class EcposOrderSendTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private function ecpos(bool $on, $response = null): void
    {
        config(['ecpos.api_key' => 'test-key', 'ecpos.base_url' => 'https://ecpos.test/api/external/orders', 'ecpos.send_orders' => $on]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['ecpos.test/*' => $response ?? Http::response(['journalid' => 123456], 200)]);
    }

    /** a store linked to ECPOS, a pending order with one ECPOS item (qty 3) and one hand-made item (qty 2) */
    private function pendingOrder(bool $linked = true): array
    {
        $store = $this->makeStore();
        if ($linked) {
            $store->forceFill(['ecpos_store_id' => 'BW0018'])->save();
        }
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();
        $items['bw']->forceFill(['synced_at' => now()])->save(); // BW001 comes from ECPOS; WH001 does not
        $order = $this->seedOrder($store, $user, 'pending', now()->toDateString(), [[$items['bw'], 3], [$items['wh'], 2]]);

        return [$user, $order];
    }

    public function test_posting_sends_the_order_to_ecpos_and_keeps_its_journal_id(): void
    {
        $this->ecpos(true);
        [$user, $order] = $this->pendingOrder();

        $this->actingAs($user)->post("/orders/{$order->id}/post")->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('posted', $order->status);
        $this->assertSame('123456', $order->ecpos_journal_id);
        $this->assertNotNull($order->ecpos_sent_at);
        $this->assertStringContainsString('WH001', $order->ecpos_note); // the non-ECPOS item was left out and recorded

        Http::assertSent(function ($r) use ($order) {
            return $r->method() === 'POST' && $r->url() === 'https://ecpos.test/api/external/orders' && $r->hasHeader('X-API-Key', 'test-key')
                && $r['store'] === 'BW0018' && str_contains($r['description'], $order->reference())
                && $r['items'] === [['itemid' => 'BW001', 'qty' => 3]];
        });
        Http::assertSentCount(1); // never retried

        $props = $this->actingAs($admin = $this->makeAdmin())->get("/orders/{$order->id}")->viewData('page')['props']['order'];
        $this->assertSame('123456', $props['ecpos']['journal_id']);
        $this->assertContains('sent', array_column($props['events'], 'event'));

        // the store never sees anything about ECPOS on its own order
        $mine = $this->actingAs($user)->get("/orders/{$order->id}")->viewData('page')['props'];
        $this->assertNull($mine['order']['ecpos']);
        $this->assertNull($mine['order']['ecpos_state']);
        $this->assertFalse($mine['can']['send_ecpos']);
        $this->assertNotContains('sent', array_column($mine['order']['events'], 'event'));
    }

    public function test_if_ecpos_refuses_the_order_it_is_still_posted_but_flagged_and_can_be_sent_again(): void
    {
        $this->ecpos(true, Http::response(['message' => 'Item not allowed'], 422));
        [$user, $order] = $this->pendingOrder();

        // posting is instant and doesn't depend on ECPOS: the problem shows up on the order, not as a blocked store
        $this->actingAs($user)->post("/orders/{$order->id}/post")->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('posted', $order->status);
        $this->assertSame('failed', $order->ecpos_state);
        $this->assertStringContainsString('Item not allowed', $order->ecpos_error);
        $this->assertNull($order->ecpos_sent_at);
        $this->assertSame(1, $order->events()->where('event', 'send_failed')->count());

        $admin = $this->makeAdmin();
        $props = $this->actingAs($admin)->get("/orders/{$order->id}")->viewData('page')['props'];
        $this->assertSame('failed', $props['order']['ecpos']['state']);
        $this->assertTrue($props['can']['send_ecpos']);
        $this->actingAs($user)->post("/orders/{$order->id}/ecpos-send")->assertForbidden(); // a store can't resend

        // fixed on ECPOS's side -> send again
        $this->ecpos(true);
        $this->actingAs($admin)->post("/orders/{$order->id}/ecpos-send")->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(['sent', '123456', null], [$order->ecpos_state, $order->ecpos_journal_id, $order->ecpos_error]);
    }
    public function test_a_store_that_is_not_linked_to_ecpos_cannot_post_while_sending_is_on(): void
    {
        $this->ecpos(true);
        [$user, $order] = $this->pendingOrder(linked: false);

        $this->actingAs($user)->post("/orders/{$order->id}/post")->assertSessionHasErrors('ecpos');
        $this->assertSame('pending', $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_while_the_switch_is_off(): void
    {
        $this->ecpos(false);
        [$user, $order] = $this->pendingOrder(linked: false);

        $this->actingAs($user)->post("/orders/{$order->id}/post")->assertSessionHasNoErrors();
        $this->assertSame('posted', $order->fresh()->status);
        $this->assertNull($order->fresh()->ecpos_sent_at);
        Http::assertNothingSent();
    }

    public function test_an_order_with_only_non_ecpos_items_is_refused(): void
    {
        $this->ecpos(true);
        $store = $this->makeStore();
        $store->forceFill(['ecpos_store_id' => 'BW0018'])->save();
        $user = $this->makeStoreUser($store);
        $order = $this->seedOrder($store, $user, 'pending', now()->toDateString(), [[$this->makeItems()['wh'], 2]]);

        $this->actingAs($user)->post("/orders/{$order->id}/post")->assertSessionHasErrors('ecpos');
        $this->assertSame('pending', $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_the_order_page_can_read_ecpos_status_for_a_sent_order_for_admins_only(): void
    {
        $this->ecpos(true, Http::response(['journalid' => 4700000004, 'store' => 'DEMO STORE 1', 'status' => 'pending', 'created_at' => '2026-10-09 15:05:11', 'items' => [['itemid' => 'BW001', 'itemname' => 'Ube Cake', 'qty' => '3'], ['itemid' => 'X', 'itemname' => 'Y', 'qty' => '2']]], 200));
        [$user, $order] = $this->pendingOrder();
        $order->forceFill(['status' => 'posted', 'ecpos_journal_id' => '4700000004', 'ecpos_sent_at' => now()])->save();

        $admin = $this->makeAdmin();
        $this->actingAs($user)->getJson("/orders/{$order->id}/ecpos-status")->assertForbidden(); // stores never see ECPOS details
        $this->actingAs($admin)->getJson("/orders/{$order->id}/ecpos-status")->assertOk()
            ->assertJson(['status' => 'pending', 'store' => 'DEMO STORE 1', 'items' => 2, 'units' => 5]);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://ecpos.test/api/external/orders/4700000004');

        // an order that was never sent has no status
        $unsent = $this->seedOrder($user->store, $user, 'posted', '2026-10-01', [[Item::where('product_code', 'BW002')->firstOrFail(), 1]]);
        $this->actingAs($admin)->getJson("/orders/{$unsent->id}/ecpos-status")->assertNotFound();
    }

    public function test_an_unreachable_ecpos_gives_a_friendly_error_not_a_crash(): void
    {
        $this->ecpos(true, Http::response('boom', 500));
        [$user, $order] = $this->pendingOrder();
        $order->forceFill(['status' => 'posted', 'ecpos_journal_id' => '77', 'ecpos_sent_at' => now()])->save();

        $this->actingAs($this->makeAdmin())->getJson("/orders/{$order->id}/ecpos-status")->assertStatus(502)->assertJsonStructure(['message']);
    }

    public function test_a_posted_order_that_never_reached_ecpos_can_be_sent_once(): void
    {
        $this->ecpos(true);
        [$user, $order] = $this->pendingOrder();
        $order->forceFill(['status' => 'posted', 'posted_at' => now()])->save(); // posted while sending was off

        $admin = $this->makeAdmin();
        $props = $this->actingAs($admin)->get("/orders/{$order->id}")->viewData('page')['props'];
        $this->assertTrue($props['can']['send_ecpos']);

        $this->actingAs($admin)->post("/orders/{$order->id}/ecpos-send")->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('123456', $order->ecpos_journal_id);
        $this->assertSame(1, $order->events()->where('event', 'sent')->count());

        // never twice
        $this->actingAs($admin)->post("/orders/{$order->id}/ecpos-send")->assertSessionHasErrors('ecpos');
        $posts = Http::recorded()->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), 'ecpos.test'))->count();
        $this->assertSame(1, $posts, 'only one POST ever reached ECPOS');
        $this->assertFalse($this->actingAs($admin)->get("/orders/{$order->id}")->viewData('page')['props']['can']['send_ecpos']);

        // a pending order isn't sent this way, and nothing is sent while the switch is off
        $pending = $this->seedOrder($user->store, $user, 'pending', '2026-10-02', [[Item::where('product_code', 'BW002')->firstOrFail(), 1]]);
        $this->actingAs($admin)->post("/orders/{$pending->id}/ecpos-send")->assertSessionHasErrors('ecpos');
    }

    public function test_the_tr_number_becomes_tr_plus_the_ecpos_journal_id_once_sent(): void
    {
        $this->ecpos(true, Http::response(['journalid' => 47000000367], 200));
        [$user, $order] = $this->pendingOrder();
        $reference = $order->reference();
        $this->assertStringStartsWith('TR-BW0018-', $order->number()); // before it is sent: our own per-store reference

        $this->actingAs($user)->post("/orders/{$order->id}/post")->assertSessionHasNoErrors();
        $order->refresh();

        $this->assertSame('TR47000000367', $order->number());
        $this->assertSame($reference, $order->reference(), 'our reference never changes');

        // the lists and the page show the ECPOS number, and either number finds the order
        $page = $this->actingAs($user)->get("/orders/{$order->id}")->viewData('page')['props']['order'];
        $this->assertSame(['TR47000000367', $reference], [$page['number'], $page['reference']]);
        foreach (['TR47000000367', '47000000367', $reference, '000001'] as $search) {
            $rows = $this->actingAs($user)->get('/orders?search='.urlencode($search))->viewData('page')['props']['orders']['data'];
            $this->assertSame([$order->id], collect($rows)->pluck('id')->all(), "search for $search");
        }

        // an order that hasn't been sent keeps our reference as its TR number
        $other = $this->seedOrder($user->store, $user, 'pending', '2026-10-02', [[Item::where('product_code', 'BW002')->firstOrFail(), 1]]);
        $this->assertSame($other->reference(), $other->number());
    }
}

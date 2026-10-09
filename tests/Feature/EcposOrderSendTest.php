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
                && $r['store'] === 'BW0018' && str_contains($r['description'], $order->number())
                && $r['items'] === [['itemid' => 'BW001', 'qty' => 3]];
        });
        Http::assertSentCount(1); // never retried

        $props = $this->actingAs($user)->get("/orders/{$order->id}")->viewData('page')['props']['order'];
        $this->assertSame('123456', $props['ecpos']['journal_id']);
    }

    public function test_if_ecpos_refuses_the_order_stays_pending_and_can_be_posted_again(): void
    {
        $this->ecpos(true, Http::response(['message' => 'Item not allowed'], 422));
        [$user, $order] = $this->pendingOrder();

        $this->actingAs($user)->post("/orders/{$order->id}/post")->assertSessionHasErrors('ecpos');
        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->ecpos_sent_at);
        $this->assertSame(0, $order->events()->where('event', 'posted')->count());

        $this->ecpos(true);
        $this->actingAs($user)->post("/orders/{$order->id}/post")->assertSessionHasNoErrors();
        $this->assertSame('posted', $order->fresh()->status);
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

    public function test_the_order_page_can_read_ecpos_status_for_a_sent_order_but_only_for_your_own_store(): void
    {
        $this->ecpos(true, Http::response(['journalid' => 4700000004, 'store' => 'DEMO STORE 1', 'status' => 'pending', 'created_at' => '2026-10-09 15:05:11', 'items' => [['itemid' => 'BW001', 'itemname' => 'Ube Cake', 'qty' => '3'], ['itemid' => 'X', 'itemname' => 'Y', 'qty' => '2']]], 200));
        [$user, $order] = $this->pendingOrder();
        $order->forceFill(['status' => 'posted', 'ecpos_journal_id' => '4700000004', 'ecpos_sent_at' => now()])->save();

        $this->actingAs($user)->getJson("/orders/{$order->id}/ecpos-status")->assertOk()
            ->assertJson(['status' => 'pending', 'store' => 'DEMO STORE 1', 'items' => 2, 'units' => 5]);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://ecpos.test/api/external/orders/4700000004');

        // another store can't see it; an order that was never sent has no status
        $other = $this->makeStoreUser($this->makeStore('OT01', true, 'Other'));
        $this->actingAs($other)->getJson("/orders/{$order->id}/ecpos-status")->assertNotFound();
        $unsent = $this->seedOrder($user->store, $user, 'posted', '2026-10-01', [[Item::where('product_code', 'BW002')->firstOrFail(), 1]]);
        $this->actingAs($user)->getJson("/orders/{$unsent->id}/ecpos-status")->assertNotFound();
    }

    public function test_an_unreachable_ecpos_gives_a_friendly_error_not_a_crash(): void
    {
        $this->ecpos(true, Http::response('boom', 500));
        [$user, $order] = $this->pendingOrder();
        $order->forceFill(['status' => 'posted', 'ecpos_journal_id' => '77', 'ecpos_sent_at' => now()])->save();

        $this->actingAs($user)->getJson("/orders/{$order->id}/ecpos-status")->assertStatus(502)->assertJsonStructure(['message']);
    }

    public function test_a_posted_order_that_never_reached_ecpos_can_be_sent_once(): void
    {
        $this->ecpos(true);
        [$user, $order] = $this->pendingOrder();
        $order->forceFill(['status' => 'posted', 'posted_at' => now()])->save(); // posted while sending was off

        $props = $this->actingAs($user)->get("/orders/{$order->id}")->viewData('page')['props'];
        $this->assertTrue($props['can']['send_ecpos']);

        $this->actingAs($user)->post("/orders/{$order->id}/ecpos-send")->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('123456', $order->ecpos_journal_id);
        $this->assertSame(1, $order->events()->where('event', 'sent')->count());

        // never twice
        $this->actingAs($user)->post("/orders/{$order->id}/ecpos-send")->assertSessionHasErrors('ecpos');
        $posts = Http::recorded()->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), 'ecpos.test'))->count();
        $this->assertSame(1, $posts, 'only one POST ever reached ECPOS');
        $this->assertFalse($this->actingAs($user)->get("/orders/{$order->id}")->viewData('page')['props']['can']['send_ecpos']);

        // a pending order isn't sent this way, and nothing is sent while the switch is off
        $pending = $this->seedOrder($user->store, $user, 'pending', '2026-10-02', [[Item::where('product_code', 'BW002')->firstOrFail(), 1]]);
        $this->actingAs($user)->post("/orders/{$pending->id}/ecpos-send")->assertSessionHasErrors('ecpos');
    }
}

<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class StoreOrderingTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-10-09 10:00'); // well before the 4:00 PM default cutoff
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_store_can_order_bw_products_and_warehouse_items(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();

        $response = $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 12], [$items['wh'], 3], [$items['bw2'], 5]], null, ['notes' => 'Morning delivery']));

        $order = Order::where('status', '!=', 'draft')->firstOrFail();
        $response->assertRedirect("/orders/{$order->id}");

        $this->assertSame($store->id, $order->store_id);
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->posted_at);
        $this->assertSame('Morning delivery', $order->notes);
        $this->assertSame('TR-SF01-000001', $order->number());
        $this->assertSame(['bw_products' => 17, 'warehouse' => 3], $order->lines->groupBy('source')->map->sum('quantity')->all());
        $this->assertSame(['placed'], $order->events->pluck('event')->all());

        // snapshots come from the database, not the request
        $line = $order->lines->firstWhere('product_code', 'BW001');
        $this->assertSame('Ube Cake', $line->name);
        $this->assertSame('CAKES', $line->category);
    }

    public function test_invalid_quantities_and_unknown_products_are_rejected(): void
    {
        $user = $this->makeStoreUser($this->makeStore());
        $items = $this->makeItems();
        $this->actingAs($user);

        $bad = [
            'zero' => [[$items['bw'], 0]],
            'negative' => [[$items['bw'], -4]],
            'text' => [[$items['bw'], 'lots']],
            'fraction' => [[$items['bw'], 1.5]],
            'too many' => [[$items['bw'], 10000]],
            'unknown product' => [[99999, 2]],
            'no lines' => [],
        ];

        foreach ($bad as $label => $lines) {
            $this->post('/orders', $this->payload($lines))->assertSessionHasErrors();
            $this->assertSame(0, Order::where('status', '!=', 'draft')->count(), "$label created an order");
        }

    }

    public function test_deleted_products_cannot_be_ordered(): void
    {
        $user = $this->makeStoreUser($this->makeStore());
        $items = $this->makeItems();
        $gone = $items['wh'];
        $gone->delete();

        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 1], [$gone->id, 2]]))->assertSessionHasErrors('lines.1.item_id');
        $this->assertSame(0, Order::where('status', '!=', 'draft')->count());
    }

    public function test_the_same_product_twice_is_merged_into_one_line(): void
    {
        $user = $this->makeStoreUser($this->makeStore());
        $items = $this->makeItems();

        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 2], [$items['bw'], 3]]))->assertRedirect();

        $order = Order::where('status', 'pending')->firstOrFail();
        $this->assertCount(1, $order->lines);
        $this->assertSame(5, $order->lines->first()->quantity);
    }

    public function test_a_failed_submission_leaves_no_partial_order(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();

        // blow up while saving the second line, i.e. half way through the order
        $count = 0;
        OrderItem::creating(function () use (&$count) {
            if (++$count === 2) {
                throw new \RuntimeException('simulated failure');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 2], [$items['wh'], 3], [$items['bw2'], 4]]));
            $this->fail('the simulated failure should have propagated');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        } finally {
            OrderItem::flushEventListeners(); // don't leak the failing listener into other tests
        }

        $this->assertSame(0, OrderItem::count(), 'no lines were kept');
        $this->assertSame(0, Order::where('status', '!=', 'draft')->count(), 'no placed order exists');
        $this->assertSame(0, \App\Models\OrderEvent::count());
    }

    public function test_an_inactive_store_cannot_order_but_keeps_its_history(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();
        $old = $this->seedOrder($store, $user, 'posted', '2026-10-01', [[$items['bw'], 2]]);
        $store->update(['is_active' => false]);

        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 1]]))->assertSessionHasErrors('deadline');
        $this->actingAs($user)->postJson('/orders/draft')->assertStatus(422);
        $this->assertSame(1, Order::where('status', '!=', 'draft')->count());

        // history still readable
        $this->actingAs($user)->get("/orders/{$old->id}")->assertOk();
        $this->actingAs($user)->get('/orders')->assertOk();
    }

    public function test_a_store_posts_its_own_pending_order(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $order = $this->seedOrder($store, $user, 'pending', null, [[$this->makeItems()['bw'], 4]]);

        $this->actingAs($user)->post("/orders/{$order->id}/post")->assertRedirect();

        $order->refresh();
        $this->assertSame('posted', $order->status);
        $this->assertEquals(now()->timestamp, $order->posted_at->timestamp);
        $this->assertSame(['posted'], $order->events->pluck('event')->all());

        // posting twice is refused and changes nothing
        $this->actingAs($user)->post("/orders/{$order->id}/post")->assertStatus(422);
        $this->assertCount(1, $order->fresh()->events);
    }

    public function test_only_one_active_order_per_store_per_date(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();
        $this->actingAs($user);

        $this->post('/orders', $this->payload([[$items['bw'], 1]]))->assertRedirect();
        $first = Order::where('status', 'pending')->firstOrFail();

        // a second one for the same day is refused, whether the first is pending or posted
        $this->post('/orders', $this->payload([[$items['bw'], 9]]))->assertSessionHasErrors('order_date');
        $this->post("/orders/{$first->id}/post");
        $this->post('/orders', $this->payload([[$items['bw'], 9]]))->assertSessionHasErrors('order_date');

        // another day is fine (the date is chosen when the order is started, not on the sheet)
        $this->put('/orders/draft', ['order_date' => '2026-10-10'])->assertRedirect();
        $this->post('/orders', $this->payload([[$items['bw'], 2]], '2026-10-10'))->assertRedirect();
        $this->assertSame(2, Order::whereIn('status', ['pending', 'posted'])->count());

        // with the rule switched off, same-day orders are allowed
        AppSetting::write('one_order_per_day', '0');
        $this->post('/orders', $this->payload([[$items['bw'], 3]]))->assertRedirect();
        $this->assertSame(3, Order::whereIn('status', ['pending', 'posted'])->count());
    }

    /* ---- the "you already have an order" check behind the New order button ---- */

    public function test_clicking_new_order_with_a_posted_tr_today_says_so_and_reserves_nothing(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $order = $this->seedOrder($store, $user, 'posted', '2026-10-09', [[$this->makeItems()['bw'], 3]]);

        $this->actingAs($user)->postJson('/orders/draft')
            ->assertStatus(409)
            ->assertJson([
                'error' => 'already_ordered',
                'order' => ['id' => $order->id, 'number' => $order->number(), 'status' => 'posted', 'order_date' => '2026-10-09', 'url' => url("/orders/{$order->id}")],
            ])
            ->assertJsonPath('message', fn ($m) => str_contains($m, $order->number()) && str_contains($m, 'already has'));

        $this->assertSame(0, Order::where('status', 'draft')->count(), 'no TR number is reserved when the store already ordered');
    }

    public function test_a_pending_tr_blocks_a_new_order_the_same_way(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $order = $this->seedOrder($store, $user, 'pending', '2026-10-09', [[$this->makeItems()['bw'], 3]]);

        $this->actingAs($user)->postJson('/orders/draft')->assertStatus(409)->assertJsonPath('order.status', 'pending')->assertJsonPath('order.number', $order->number());
    }

    public function test_a_cancelled_tr_does_not_block_a_new_order(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $this->seedOrder($store, $user, 'cancelled', '2026-10-09');

        $this->actingAs($user)->postJson('/orders/draft')->assertOk()->assertJsonPath('order_date', '2026-10-09');
    }

    public function test_another_stores_tr_does_not_block_this_store(): void
    {
        $a = $this->makeStore('AA01');
        $b = $this->makeStore('BB01');
        $this->seedOrder($a, $this->makeStoreUser($a), 'posted', '2026-10-09');

        $this->actingAs($this->makeStoreUser($b))->postJson('/orders/draft')->assertOk();
    }

    public function test_a_store_with_a_tr_today_can_still_start_an_order_for_another_date(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $this->seedOrder($store, $user, 'posted', '2026-10-09');

        $this->actingAs($user)->postJson('/orders/draft?date=2026-10-10')->assertOk()->assertJsonPath('order_date', '2026-10-10');
        $this->assertSame('2026-10-10', Order::where('status', 'draft')->firstOrFail()->order_date->toDateString());

        // ...but not for a date that already has one
        $this->seedOrder($store, $user, 'pending', '2026-10-11');
        $this->actingAs($user)->postJson('/orders/draft?date=2026-10-11')->assertStatus(409)->assertJsonPath('order.order_date', '2026-10-11');
    }

    public function test_the_check_is_skipped_when_the_one_order_per_day_rule_is_off(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $this->seedOrder($store, $user, 'posted', '2026-10-09');
        AppSetting::write('one_order_per_day', '0');

        $this->actingAs($user)->postJson('/orders/draft')->assertOk();
    }

    public function test_a_bad_date_for_the_new_order_check_is_rejected(): void
    {
        $user = $this->makeStoreUser($this->makeStore());

        foreach (['nope', '2030-01-01', '2020-01-01', '2026-13-40'] as $bad) {
            $this->actingAs($user)->postJson('/orders/draft?date='.$bad)->assertStatus(422)->assertJsonValidationErrors('date');
        }
        $this->assertSame(0, Order::where('status', 'draft')->count());
    }

    public function test_the_deadline_is_still_checked_before_the_existing_order(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $this->seedOrder($store, $user, 'posted', '2026-10-09');
        $this->at('2026-10-09 17:00'); // after the cutoff

        $this->actingAs($user)->postJson('/orders/draft')->assertStatus(422)->assertJsonValidationErrors('deadline');
    }

    public function test_opening_the_order_sheet_directly_is_blocked_when_the_date_is_taken(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $order = $this->seedOrder($store, $user, 'posted', '2026-10-09');

        $blocked = $this->actingAs($user)->get('/orders/create')->viewData('page')['props']['blocked'];
        $this->assertStringContainsString($order->number(), $blocked);
        $this->assertStringContainsString('already has', $blocked);

        // and the server still refuses the submission itself
        $items = $this->makeItems();
        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 1]]))->assertSessionHasErrors('order_date');
    }

    public function test_the_order_sheet_is_open_when_the_store_has_no_order_for_that_date(): void
    {
        $user = $this->makeStoreUser($this->makeStore());

        $this->assertNull($this->actingAs($user)->get('/orders/create')->viewData('page')['props']['blocked']);
    }

    public function test_a_store_cannot_cancel_but_can_reset_its_pending_order_to_edit_and_place_it_again(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();
        $this->actingAs($user);

        $this->post('/orders', $this->payload([[$items['bw'], 1]]));
        $order = Order::where('status', 'pending')->firstOrFail();

        $this->assertFalse($this->get("/orders/{$order->id}")->viewData('page')['props']['can']['cancel']);
        $this->assertTrue($this->get("/orders/{$order->id}")->viewData('page')['props']['can']['reset']);
        $this->post("/orders/{$order->id}/cancel", ['reason' => 'nope'])->assertForbidden();
        $this->assertSame('pending', $order->fresh()->status);

        // reset: same TR goes back to a draft with its items, and the sheet opens with them
        $this->post("/orders/{$order->id}/reset")->assertRedirect('/orders/create');
        $this->assertSame('draft', $order->fresh()->status);
        $props = $this->get('/orders/create')->viewData('page')['props'];
        $this->assertSame($order->id, $props['order']['id']);
        $this->assertSame([[$items['bw']->id, 1]], collect($props['saved']['lines'])->map(fn ($l) => [$l['item_id'], $l['quantity']])->all());
        $this->assertSame(0, Order::where('status', 'pending')->count(), 'a reset order does not count until placed again');

        // edit (add an item) and place it again: same TR, now pending again
        $this->post('/orders', $this->payload([[$items['bw'], 5], [$items['wh'], 2]]))->assertRedirect();
        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertSame(2, $order->lines()->count());
        $this->assertSame(['placed', 'reset', 'placed'], $order->events()->orderBy('id')->pluck('event')->all());
    }

    public function test_a_posted_order_cannot_be_reset_and_other_stores_cannot_touch_it(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $posted = $this->seedOrder($store, $user, 'posted', null, [[$this->makeItems()['bw'], 2]]);
        $this->actingAs($user)->post("/orders/{$posted->id}/reset")->assertSessionHasErrors('reset');
        $this->assertSame('posted', $posted->fresh()->status);

        $other = $this->makeStoreUser($this->makeStore('OT01', true, 'Other'));
        $this->actingAs($other)->post("/orders/{$posted->id}/reset")->assertNotFound();
        $this->actingAs($this->makeAdmin())->post("/orders/{$posted->id}/reset")->assertForbidden();
    }

    public function test_a_store_cannot_cancel_a_posted_order(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $order = $this->seedOrder($store, $user, 'posted', null, [[$this->makeItems()['bw'], 2]]);

        $this->actingAs($user)->post("/orders/{$order->id}/cancel", ['reason' => 'oops'])->assertForbidden();
        $this->assertSame('posted', $order->fresh()->status);
    }

    public function test_history_shows_every_status_including_cancelled(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $this->seedOrder($store, $user, 'pending', '2026-10-08');
        $this->seedOrder($store, $user, 'posted', '2026-10-07');
        $cancelled = $this->seedOrder($store, $user, 'cancelled', '2026-10-06');
        $cancelled->update(['cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancellation_reason' => 'duplicate']);

        $rows = $this->actingAs($user)->get('/orders')->viewData('page')['props']['orders']['data'];
        $this->assertEqualsCanonicalizing(['pending', 'posted', 'cancelled'], collect($rows)->pluck('status')->all());

        $detail = $this->actingAs($user)->get("/orders/{$cancelled->id}")->viewData('page')['props']['order'];
        $this->assertSame('duplicate', $detail['cancellation_reason']);
    }

    public function test_item_catalog_is_visible_to_stores_for_both_catalogs(): void
    {
        $user = $this->makeStoreUser($this->makeStore());
        $this->makeItems();

        foreach (['bw_products' => 2, 'warehouse' => 2] as $source => $expected) {
            $page = $this->actingAs($user)->get("/retails?source=$source")->viewData('page')['props'];
            $this->assertCount($expected, $page['items']['data']);
            $this->assertSame($source, $page['filters']['source']);
        }

        // the order sheet lists both catalogs
        $items = $this->actingAs($user)->get('/orders/create')->viewData('page')['props']['items'];
        $this->assertSame(['bw_products', 'warehouse'], collect($items)->pluck('source')->unique()->sort()->values()->all());
        $this->assertSame(4, Item::count());
    }

    public function test_the_order_date_cannot_be_changed_from_the_order_sheet(): void
    {
        $user = $this->makeStoreUser($this->makeStore());
        $items = $this->makeItems();
        $this->actingAs($user);

        $this->put('/orders/draft', ['order_date' => '2026-10-12'])->assertRedirect();

        // autosave and placing both ignore any date the sheet sends
        $this->putJson('/orders/draft/lines', ['order_date' => '2026-10-20', 'lines' => [['item_id' => $items['bw']->id, 'quantity' => 1]]])->assertOk();
        $this->post('/orders', $this->payload([[$items['bw'], 1]], '2026-10-25'))->assertRedirect();

        $this->assertSame('2026-10-12', Order::where('status', 'pending')->firstOrFail()->order_date->toDateString());
    }

    public function test_each_store_has_its_own_tr_series_like_tr_store_000001(): void
    {
        $items = $this->makeItems();
        $a = $this->makeStore('AA01', true, 'Store A');
        $a->forceFill(['ecpos_store_id' => 'BW0018'])->save(); // the ECPOS store ID is used in the number when there is one
        $b = $this->makeStore('BB01', true, 'Store B');
        $userA = $this->makeStoreUser($a);
        $userB = $this->makeStoreUser($b);

        // the number reserved when the prompt opens is the number the order keeps
        $reserved = $this->actingAs($userA)->postJson('/orders/draft')->json('number');
        $this->assertSame('TR-BW0018-000001', $reserved);
        $this->assertSame($reserved, $this->postJson('/orders/draft')->json('number'), 'asking again returns the same draft');
        $this->post('/orders', $this->payload([[$items['bw'], 1]], '2026-10-09'))->assertRedirect();

        // same store, next day -> the next number in its series
        $this->actingAs($userA)->put('/orders/draft', ['order_date' => '2026-10-10']);
        $this->post('/orders', $this->payload([[$items['bw'], 1]], '2026-10-10'))->assertRedirect();

        // another store starts its own series, and the numbers never collide
        $this->actingAs($userB)->put('/orders/draft', ['order_date' => '2026-10-09']);
        $this->post('/orders', $this->payload([[$items['bw'], 1]], '2026-10-09'))->assertRedirect();

        $numbers = Order::orderBy('id')->get()->map->number()->all();
        $this->assertSame(['TR-BW0018-000001', 'TR-BW0018-000002', 'TR-BB01-000001'], $numbers);
        $this->assertCount(3, array_unique($numbers));

        // renaming the store's ECPOS ID later never changes an existing TR
        $a->forceFill(['ecpos_store_id' => 'BW9999'])->save();
        $this->assertSame('TR-BW0018-000001', Order::orderBy('id')->first()->number());
    }

    public function test_copy_last_order_returns_the_stores_latest_order_with_only_available_items(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $other = $this->makeStoreUser($this->makeStore('OT01', true, 'Other'));
        $items = $this->makeItems();

        // nothing yet
        $this->actingAs($user)->getJson('/orders/last')->assertOk()->assertJson(['order' => null]);

        $old = $this->seedOrder($store, $user, 'posted', '2026-10-01', [[$items['bw'], 9]]);
        $last = $this->seedOrder($store, $user, 'pending', '2026-10-05', [[$items['bw'], 4], [$items['bw2'], 6], [$items['wh'], 2]]);
        $this->seedOrder($other->store, $other, 'posted', '2026-10-08', [[$items['wh2'], 50]]); // another store's order is never used

        // an item that has since been archived is left out and counted
        $items['wh']->forceFill(['archived_at' => now()])->save();

        $res = $this->actingAs($user)->getJson('/orders/last')->assertOk()->json('order');
        $this->assertSame($last->number(), $res['number']);
        $this->assertSame(1, $res['unavailable']);
        $this->assertEqualsCanonicalizing([[$items['bw']->id, 4], [$items['bw2']->id, 6]], collect($res['lines'])->map(fn ($l) => [$l['item_id'], $l['quantity']])->all());

        // a draft never counts, and admins can't use it
        $this->actingAs($user)->putJson('/orders/draft/lines', ['lines' => [['item_id' => $items['bw']->id, 'quantity' => 1]]]);
        $this->assertSame($last->number(), $this->actingAs($user)->getJson('/orders/last')->json('order.number'));
        $this->actingAs($this->makeAdmin())->getJson('/orders/last')->assertForbidden();
    }
}

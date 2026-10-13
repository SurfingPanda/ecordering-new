<?php

namespace Tests\Feature;

use App\Mail\OrderPosted;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class AreaManagerTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    /** two stores for the manager (A1, A2), one they do not handle (B1), each with a posted order today */
    private array $f = [];

    protected function setUp(): void
    {
        parent::setUp();
        $items = $this->makeItems();
        $today = now()->toDateString();

        foreach (['A1' => 'Alpha One', 'A2' => 'Alpha Two', 'B1' => 'Bravo One'] as $code => $name) {
            $store = $this->makeStore($code, true, $name);
            $user = $this->makeStoreUser($store, strtolower($code).'@store.test');
            $order = $this->seedOrder($store, $user, 'posted', $today, [[$items['bw'], $code === 'B1' ? 50 : 5], [$items['wh'], 2]]);
            $this->f[$code] = ['store' => $store, 'user' => $user, 'order' => $order];
        }
        $this->f['items'] = $items;
        $this->f['manager'] = $this->manager([$this->f['A1']['store']->id, $this->f['A2']['store']->id]);
    }

    private function manager(array $storeIds, string $email = 'am@bw.test'): User
    {
        $m = new User(['name' => 'Area Manager', 'email' => $email, 'password' => 'secret-pass-1']);
        $m->forceFill(['role' => User::AREA_MANAGER, 'store_id' => null])->save();
        $m->managedStores()->sync($storeIds);

        return $m;
    }

    private function props($response): array
    {
        return $response->viewData('page')['props'];
    }

    public function test_a_manager_only_sees_the_orders_of_their_stores(): void
    {
        $m = $this->f['manager'];

        $list = $this->props($this->actingAs($m)->get('/orders')->assertOk());
        $codes = collect($list['orders']['data'])->pluck('store.code')->sort()->values()->all();
        $this->assertSame(['A1', 'A2'], $codes);
        $this->assertSame(['A1', 'A2'], collect($list['stores'])->pluck('code')->sort()->values()->all()); // the filter lists only their stores

        $this->actingAs($m)->get("/orders/{$this->f['A1']['order']->id}")->assertOk();
        $this->actingAs($m)->get("/orders/{$this->f['B1']['order']->id}")->assertNotFound(); // another store: not even a hint it exists
        $this->actingAs($m)->get('/orders?store='.$this->f['B1']['store']->id)->assertOk()->assertInertia(fn ($p) => $p->has('orders.data', 0)); // filtering to it returns nothing
    }

    public function test_the_order_page_shows_a_manager_the_ecpos_details_and_lets_them_cancel(): void
    {
        $m = $this->f['manager'];
        $order = $this->f['A1']['order'];
        $order->forceFill(['ecpos_state' => 'sent', 'ecpos_journal_id' => '4700000099', 'ecpos_sent_at' => now()])->save();

        $props = $this->props($this->actingAs($m)->get("/orders/{$order->id}"));
        $this->assertSame('4700000099', $props['order']['ecpos']['journal_id']);
        $this->assertTrue($props['can']['cancel']);
        $this->assertFalse($props['can']['post']); // placing orders stays with the store
        $this->assertFalse($this->props($this->actingAs($this->f['A1']['user'])->get("/orders/{$order->id}"))['can']['cancel']);
    }

    public function test_a_manager_can_cancel_a_posted_tr_of_their_store_and_only_theirs(): void
    {
        $m = $this->f['manager'];
        $order = $this->f['A2']['order'];

        $this->actingAs($m)->post("/orders/{$order->id}/cancel", [])->assertSessionHasErrors('reason'); // a reason is needed, as for admins
        $this->actingAs($m)->post("/orders/{$order->id}/cancel", ['reason' => 'Wrong quantities'])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(['cancelled', $m->id, 'Wrong quantities'], [$order->status, $order->cancelled_by, $order->cancellation_reason]);
        $this->assertSame(1, $order->events()->where('event', 'cancelled')->where('user_id', $m->id)->count());

        $other = $this->f['B1']['order'];
        $this->actingAs($m)->post("/orders/{$other->id}/cancel", ['reason' => 'Not mine to cancel'])->assertNotFound();
        $this->assertSame('posted', $other->fresh()->status);

        // a store account still can't
        $this->actingAs($this->f['A1']['user'])->post("/orders/{$this->f['A1']['order']->id}/cancel", ['reason' => 'x y z'])->assertForbidden();
    }

    public function test_a_manager_can_send_an_order_to_ecpos_again_for_their_store_but_not_another(): void
    {
        config(['ecpos.api_key' => 'k', 'ecpos.send_orders' => true, 'queue.default' => 'database']);
        \Illuminate\Support\Facades\Queue::fake();
        foreach (['A1', 'B1'] as $c) {
            $this->f[$c]['store']->forceFill(['ecpos_store_id' => 'BW1'.$c])->save();
            $this->f[$c]['order']->forceFill(['ecpos_state' => 'failed', 'ecpos_error' => 'ECPOS is busy'])->save();
        }
        $this->f['items']['bw']->forceFill(['synced_at' => now()])->save();
        $m = $this->f['manager'];

        $this->assertTrue($this->props($this->actingAs($m)->get("/orders/{$this->f['A1']['order']->id}"))['can']['send_ecpos']);
        $this->actingAs($m)->post("/orders/{$this->f['A1']['order']->id}/ecpos-send")->assertSessionHasNoErrors();
        $this->assertSame('queued', $this->f['A1']['order']->fresh()->ecpos_state);

        $this->actingAs($m)->post("/orders/{$this->f['B1']['order']->id}/ecpos-send")->assertNotFound();
        $this->assertSame('failed', $this->f['B1']['order']->fresh()->ecpos_state);
    }

    public function test_every_report_only_counts_the_managers_stores(): void
    {
        $m = $this->f['manager'];
        // units today: A1 = 7, A2 = 7, B1 = 52 (not theirs)

        $dash = $this->props($this->actingAs($m)->get('/dashboard')->assertOk());
        $this->assertSame(2, $dash['kpis']['orders']['value']);
        $this->assertSame(14, $dash['kpis']['units']['value']);
        $this->assertSame('all', $dash['scope']);
        $this->assertEqualsCanonicalizing(['A1', 'A2'], collect($dash['recent'])->pluck('store.code')->all());

        $cons = $this->props($this->actingAs($m)->get('/admin/consolidated')->assertOk());
        $this->assertSame(14, $cons['summary']['units']);
        $this->assertSame(['A1', 'A2'], collect($cons['stores'])->pluck('code')->sort()->values()->all());
        $this->assertEqualsCanonicalizing(['A1', 'A2'], collect($cons['detail']['data'])->pluck('store_code')->unique()->all());
        $asked = $this->props($this->actingAs($m)->get('/admin/consolidated?store='.$this->f['B1']['store']->id));
        $this->assertSame(0, $asked['summary']['units']); // asking for another store gets nothing

        $csv = $this->actingAs($m)->get('/admin/consolidated/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('A1', $csv);
        $this->assertStringNotContainsString('B1', $csv);
        $this->assertStringNotContainsString('Bravo', $csv);

        $trends = $this->props($this->actingAs($m)->get('/admin/trends')->assertOk());
        $this->assertEqualsCanonicalizing(['A1', 'A2'], collect($trends['stores'])->pluck('code')->all());
        $this->assertSame(14, array_sum($trends['overall']['units']));
        $trendsItems = collect($trends['items'])->sum('sum');
        $this->assertSame(14, (int) $trendsItems);

        $today = $this->props($this->actingAs($m)->get('/admin/today')->assertOk());
        $this->assertSame(2, $today['summary']['total']);
        $this->assertSame(2, $today['summary']['ordered']);
        $this->assertEqualsCanonicalizing(['A1', 'A2'], collect($today['ordered'])->pluck('code')->all());
    }

    public function test_the_reminders_count_only_the_managers_stores(): void
    {
        $m = $this->f['manager'];
        $extra = $this->makeStore('A3', true, 'Alpha Three'); // active, handled by the manager, hasn't ordered
        $m->managedStores()->attach($extra->id);
        $this->makeStore('C1', true, 'Charlie One');          // active, not theirs, hasn't ordered: must not be counted
        $this->f['B1']['order']->forceFill(['ecpos_state' => 'failed'])->save(); // someone else's ECPOS problem

        $mine = $this->props($this->actingAs($m)->get('/dashboard'))['ordering_today'];
        $this->assertSame(3, $mine['total']);
        $this->assertSame(1, $mine['not_ordered']);
        $this->assertSame(0, $mine['ecpos_problems']);

        $this->f['A2']['order']->forceFill(['ecpos_state' => 'failed'])->save();
        $this->assertSame(1, $this->props($this->actingAs($m->fresh())->get('/dashboard'))['ordering_today']['ecpos_problems']);

        $admin = $this->makeAdmin();
        $all = $this->props($this->actingAs($admin)->get('/dashboard'))['ordering_today'];
        $this->assertSame(5, $all['total']); // an admin still sees every store
        $this->assertSame(2, $all['ecpos_problems']);
    }

    public function test_a_manager_cannot_open_admin_pages(): void
    {
        $m = $this->f['manager'];

        foreach (['/admin/settings', '/admin/stores', '/admin/categories', '/admin/sync-rules', '/admin/area-managers', '/admin/archived-products', '/retails/export'] as $url) {
            $this->actingAs($m)->get($url)->assertForbidden();
        }
        $this->actingAs($m)->post('/retails', ['product_code' => 'X1', 'description' => 'x', 'source' => 'bw_products'])->assertForbidden();
        $this->actingAs($m)->post('/admin/sync-rules', ['type' => 'group', 'match' => 'ABC', 'source' => 'warehouse'])->assertForbidden();
        $this->actingAs($m)->post('/admin/area-managers', ['name' => 'x', 'email' => 'x@y.test', 'password' => 'abcdefgh1', 'stores' => [1]])->assertForbidden();
    }

    public function test_a_manager_places_an_order_for_one_of_their_stores(): void
    {
        $m = $this->f['manager'];
        $a1 = $this->f['A1']['store'];
        $this->f['A1']['order']->update(['status' => 'cancelled']); // A1 has no active order today any more

        // the store must be named, and must be one of theirs
        $this->actingAs($m)->postJson('/orders/draft')->assertUnprocessable();
        $this->actingAs($m)->postJson('/orders/draft', ['store' => $this->f['B1']['store']->id])->assertForbidden();
        $this->actingAs($m)->get('/orders/create')->assertRedirect('/orders'); // no store picked yet

        $this->actingAs($m)->postJson('/orders/draft', ['store' => $a1->id])->assertOk()->assertJsonPath('store.code', 'A1');
        $this->actingAs($m)->get('/orders/create')->assertOk();

        $this->actingAs($m)->put('/orders/draft/lines', ['lines' => [['item_id' => $this->f['items']['bw']->id, 'quantity' => 3]]])->assertOk();
        $placed = $this->actingAs($m)->post('/orders', ['lines' => [['item_id' => $this->f['items']['bw']->id, 'quantity' => 3]]]);

        $order = Order::where('store_id', $a1->id)->where('status', 'pending')->firstOrFail();
        $placed->assertRedirect(route('orders.show', $order));
        $this->assertSame($m->id, $order->user_id);

        $this->actingAs($m)->post("/orders/{$order->id}/post")->assertRedirect();
        $this->assertSame('posted', $order->refresh()->status);
    }

    public function test_a_manager_cannot_place_or_post_for_a_store_that_is_not_theirs(): void
    {
        $m = $this->f['manager'];
        $b1 = $this->f['B1'];
        $pending = $this->seedOrder($b1['store'], $b1['user'], 'pending', now()->addDay()->toDateString(), [[$this->f['items']['bw'], 1]]);

        $this->actingAs($m)->post("/orders/{$pending->id}/post")->assertNotFound();
        $this->actingAs($m)->postJson('/orders/draft', ['store' => $b1['store']->id])->assertForbidden();
        $this->assertSame('pending', $pending->refresh()->status);
    }

    public function test_the_one_order_a_day_rule_applies_to_a_manager_too(): void
    {
        $this->actingAs($this->f['manager'])->postJson('/orders/draft', ['store' => $this->f['A1']['store']->id])->assertStatus(409);
    }

    public function test_a_manager_with_no_stores_sees_nothing(): void
    {
        $lonely = $this->manager([], 'lonely@bw.test');

        $this->assertSame([], $this->props($this->actingAs($lonely)->get('/orders'))['orders']['data']);
        $this->assertSame(0, $this->props($this->actingAs($lonely)->get('/admin/consolidated'))['summary']['units']);
        $this->assertSame([], $this->props($this->actingAs($lonely)->get('/admin/trends'))['stores']);
    }

    public function test_managers_get_the_order_emails_for_their_stores_only(): void
    {
        Mail::fake();
        config(['notifications.admin_emails' => ['boss@bw.test', 'AM@bw.test']]); // the manager is on both lists: one email

        // A1 posts: the admin list AND the manager of A1
        $a1 = $this->f['A1'];
        $pending = $this->seedOrder($a1['store'], $a1['user'], 'pending', now()->addDay()->toDateString(), [[$this->f['items']['bw'], 1]]);
        $this->actingAs($a1['user'])->post("/orders/{$pending->id}/post")->assertSessionHasNoErrors();
        Mail::assertQueued(OrderPosted::class, fn ($m) => $m->hasTo('boss@bw.test') && $m->hasTo('AM@bw.test') && count($m->to) === 2);

        // B1 posts: the admin list only; the manager of A1/A2 is not told
        Mail::fake();
        $b1 = $this->f['B1'];
        $p2 = $this->seedOrder($b1['store'], $b1['user'], 'pending', now()->addDays(2)->toDateString(), [[$this->f['items']['bw'], 1]]);
        $this->actingAs($b1['user'])->post("/orders/{$p2->id}/post")->assertSessionHasNoErrors();
        Mail::assertQueued(OrderPosted::class, fn ($m) => $m->hasTo('boss@bw.test') && ! $m->hasTo('am@bw.test'));

        // with nobody on the admin list the manager still gets theirs
        Mail::fake();
        config(['notifications.admin_emails' => []]);
        $p3 = $this->seedOrder($a1['store'], $a1['user'], 'pending', now()->addDays(3)->toDateString(), [[$this->f['items']['bw'], 1]]);
        $this->actingAs($a1['user'])->post("/orders/{$p3->id}/post")->assertSessionHasNoErrors();
        Mail::assertQueued(OrderPosted::class, fn ($m) => $m->hasTo('am@bw.test') && count($m->to) === 1);
    }

    public function test_admins_create_edit_reset_and_remove_area_managers(): void
    {
        $admin = $this->makeAdmin();
        $ids = [$this->f['A1']['store']->id, $this->f['B1']['store']->id];

        $page = $this->props($this->actingAs($admin)->get('/admin/area-managers')->assertOk());
        $this->assertCount(1, $page['managers']);
        $this->assertCount(3, $page['stores']);

        $this->actingAs($admin)->post('/admin/area-managers', ['name' => ' New Manager ', 'email' => 'New.Mgr@BW.test', 'password' => 'longenough1', 'stores' => $ids])->assertSessionHasNoErrors();
        $new = User::where('email', 'new.mgr@bw.test')->firstOrFail(); // saved in lower case
        $this->assertSame(['area_manager', null, 'New Manager'], [$new->role, $new->store_id, $new->name]);
        $this->assertTrue(Hash::check('longenough1', $new->password));
        $this->assertEqualsCanonicalizing($ids, $new->managedStores()->pluck('stores.id')->all());

        // they can sign in and see only their stores
        $this->post('/logout');
        $this->post('/login', ['email' => 'new.mgr@bw.test', 'password' => 'longenough1'])->assertRedirect();
        $this->assertAuthenticatedAs($new);
        $this->assertEqualsCanonicalizing(['A1', 'B1'], collect($this->props($this->get('/orders'))['orders']['data'])->pluck('store.code')->all());
        $this->post('/logout');

        // change the stores
        $this->actingAs($admin)->put("/admin/area-managers/{$new->id}", ['name' => 'New Manager', 'email' => 'new.mgr@bw.test', 'stores' => [$this->f['A2']['store']->id]])->assertSessionHasNoErrors();
        $this->assertSame([$this->f['A2']['store']->id], $new->managedStores()->pluck('stores.id')->all());

        // reset password
        $this->actingAs($admin)->put("/admin/area-managers/{$new->id}/password", ['password' => 'another-pass-2', 'password_confirmation' => 'another-pass-2'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('another-pass-2', $new->fresh()->password));

        // remove: gone, and the stores are untouched
        $this->actingAs($admin)->delete("/admin/area-managers/{$new->id}")->assertSessionHasNoErrors();
        $this->assertNull(User::find($new->id));
        $this->assertSame(0, DB::table('area_manager_stores')->where('user_id', $new->id)->count());
        $this->assertSame(3, Store::count());
    }

    public function test_the_area_manager_form_is_validated_and_cannot_touch_other_accounts(): void
    {
        $admin = $this->makeAdmin();
        $one = $this->f['A1']['store']->id;

        $this->actingAs($admin)->post('/admin/area-managers', ['name' => 'X', 'email' => 'x@bw.test', 'password' => 'longenough1', 'stores' => []])->assertSessionHasErrors('stores');
        $this->actingAs($admin)->post('/admin/area-managers', ['name' => 'X', 'email' => 'x@bw.test', 'password' => 'longenough1', 'stores' => [9999]])->assertSessionHasErrors('stores.0');
        $this->actingAs($admin)->post('/admin/area-managers', ['name' => 'X', 'email' => 'am@bw.test', 'password' => 'longenough1', 'stores' => [$one]])->assertSessionHasErrors('email'); // taken
        $this->actingAs($admin)->post('/admin/area-managers', ['name' => 'X', 'email' => 'x@bw.test', 'password' => 'short', 'stores' => [$one]])->assertSessionHasErrors('password');

        // this page can never be used to edit, reset or delete an admin or a store login
        $storeUser = $this->f['A1']['user'];
        $this->actingAs($admin)->put("/admin/area-managers/{$storeUser->id}", ['name' => 'Hacked', 'email' => 'h@bw.test', 'stores' => [$one]])->assertNotFound();
        $this->actingAs($admin)->put("/admin/area-managers/{$storeUser->id}/password", ['password' => 'longenough1', 'password_confirmation' => 'longenough1'])->assertNotFound();
        $this->actingAs($admin)->delete("/admin/area-managers/{$admin->id}")->assertNotFound();
        $this->assertNotNull(User::find($admin->id));
        $this->assertSame('store', $storeUser->fresh()->role);
    }
}

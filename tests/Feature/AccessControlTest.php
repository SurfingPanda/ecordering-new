<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        foreach (['/dashboard', '/orders', '/retails', '/admin/stores', '/admin/settings', '/admin/consolidated'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_store_users_cannot_use_any_admin_only_route(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $other = $this->makeStore('AN01');
        $order = $this->seedOrder($other, $this->makeStoreUser($other), 'posted');

        $this->actingAs($user);

        $this->get('/admin/stores')->assertForbidden();
        $this->get("/admin/stores/{$store->id}")->assertForbidden();
        $this->post('/admin/stores', ['name' => 'X', 'code' => 'X1', 'is_active' => true])->assertForbidden();
        $this->put("/admin/stores/{$store->id}", ['name' => 'X', 'code' => 'X1', 'is_active' => true])->assertForbidden();
        $this->patch("/admin/stores/{$store->id}/status")->assertForbidden();
        $this->post("/admin/stores/{$store->id}/users", ['name' => 'a', 'email' => 'a@a.test', 'password' => 'password123'])->assertForbidden();
        $this->get('/admin/settings')->assertForbidden();
        $this->put('/admin/settings/default', ['time' => '23:00'])->assertForbidden();
        $this->post('/admin/settings/store-deadlines', ['store_id' => $store->id, 'time' => '23:00'])->assertForbidden();
        $this->get('/admin/consolidated')->assertForbidden();
        $this->get('/admin/consolidated/export')->assertForbidden();
        $this->post('/retails', ['product_code' => 'Z', 'description' => 'z', 'source' => 'warehouse', 'retail_group' => 'non_product'])->assertForbidden();

        // and the admin-only effect did not happen
        $this->assertSame('posted', $order->fresh()->status);
    }

    public function test_admin_cannot_place_orders(): void
    {
        $admin = $this->makeAdmin();
        $items = $this->makeItems();

        $this->actingAs($admin)->get('/orders/create')->assertForbidden();
        $this->actingAs($admin)->post('/orders/draft')->assertForbidden();
        $this->actingAs($admin)->post('/orders', $this->payload([[$items['bw'], 1]]))->assertForbidden();
    }

    public function test_store_cannot_see_or_touch_another_stores_orders_by_changing_an_id(): void
    {
        $a = $this->makeStore('AA01');
        $b = $this->makeStore('BB01');
        $userA = $this->makeStoreUser($a);
        $userB = $this->makeStoreUser($b);
        $items = $this->makeItems();
        $orderB = $this->seedOrder($b, $userB, 'pending', null, [[$items['bw'], 3]]);
        $postedB = $this->seedOrder($b, $userB, 'posted', now()->addDay()->toDateString(), [[$items['bw'], 3]]);

        $this->actingAs($userA);

        $this->get("/orders/{$orderB->id}")->assertNotFound();
        $this->post("/orders/{$orderB->id}/post")->assertNotFound();
        $this->post("/orders/{$orderB->id}/cancel")->assertNotFound();
        $this->post("/orders/{$postedB->id}/cancel", ['reason' => 'trying'])->assertNotFound();

        $this->assertSame('pending', $orderB->fresh()->status);
        $this->assertSame('posted', $postedB->fresh()->status);

        // the list only ever contains the user's own store
        $this->seedOrder($a, $userA, 'posted');
        $rows = $this->get('/orders')->viewData('page')['props']['orders']['data'];
        $this->assertCount(1, $rows);
        $this->assertSame('AA01', $rows[0]['store']['code']);
    }

    public function test_store_id_and_role_cannot_be_set_from_a_request(): void
    {
        $a = $this->makeStore('AA01');
        $b = $this->makeStore('BB01');
        $userA = $this->makeStoreUser($a);
        $items = $this->makeItems();
        $this->at('2026-10-09 10:00');

        $this->actingAs($userA)->post('/orders', $this->payload([[$items['bw'], 2]], null, ['store_id' => $b->id, 'status' => 'posted', 'user_id' => 999]))->assertRedirect();

        $order = \App\Models\Order::whereNot('status', 'draft')->firstOrFail();
        $this->assertSame($a->id, $order->store_id);
        $this->assertSame('pending', $order->status);
        $this->assertSame($userA->id, $order->user_id);
    }

    public function test_dashboard_is_scoped_to_the_signed_in_stores_orders(): void
    {
        $a = $this->makeStore('AA01');
        $b = $this->makeStore('BB01');
        $ua = $this->makeStoreUser($a);
        $ub = $this->makeStoreUser($b);
        $items = $this->makeItems();
        $this->at('2026-10-09 10:00');
        $this->seedOrder($a, $ua, 'posted', now()->toDateString(), [[$items['bw'], 10]]);
        $this->seedOrder($b, $ub, 'posted', now()->toDateString(), [[$items['bw'], 90]]);

        $units = fn ($user) => $this->actingAs($user)->get('/dashboard')->viewData('page')['props']['kpis']['units']['value'];

        $this->assertSame(10, $units($ua));
        $this->assertSame(90, $units($ub));
        $this->assertSame(100, $units($this->makeAdmin()));
    }

    public function test_admin_sees_every_stores_orders(): void
    {
        $admin = $this->makeAdmin();
        foreach (['AA01', 'BB01'] as $code) {
            $s = $this->makeStore($code);
            $this->seedOrder($s, $this->makeStoreUser($s), 'posted');
        }

        $rows = $this->actingAs($admin)->get('/orders')->viewData('page')['props']['orders']['data'];
        $this->assertCount(2, $rows);
    }

    public function test_existing_pages_still_load_for_both_roles(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $admin = $this->makeAdmin();
        $this->makeItems();

        foreach ([$user, $admin] as $u) {
            foreach (['/dashboard', '/orders', '/retails', '/retails?source=warehouse'] as $url) {
                $this->actingAs($u)->get($url)->assertOk();
            }
        }
    }

    public function test_login_works_for_both_roles(): void
    {
        $store = $this->makeStore();
        $this->makeStoreUser($store, 'store@test.test');
        $this->makeAdmin('admin@test.test');

        foreach (['store@test.test', 'admin@test.test'] as $email) {
            $this->post('/login', ['email' => $email, 'password' => 'secret-pass'])->assertRedirect('/dashboard');
            $this->post('/logout');
        }
        $this->post('/login', ['email' => 'store@test.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class StoreManagementTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    public function test_admin_can_create_a_store_with_a_unique_code_and_a_login(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/admin/stores', [
            'name' => 'BW Test Store', 'code' => 'ts01', 'address' => '1 Main St', 'contact_person' => 'Ana', 'contact_number' => '+63 912 345 6789',
            'email' => 'store@example.com', 'is_active' => true,
            'user_name' => 'Test Store User', 'user_email' => 'ts01@example.com', 'user_password' => 'a-strong-password',
        ])->assertRedirect();

        $store = Store::where('code', 'TS01')->firstOrFail(); // code is normalised to upper-case
        $this->assertTrue($store->is_active);

        $user = User::where('email', 'ts01@example.com')->firstOrFail();
        $this->assertSame($store->id, $user->store_id);
        $this->assertSame('store', $user->role);
        $this->assertNotSame('a-strong-password', $user->password, 'password must be stored hashed');
        $this->assertTrue(password_verify('a-strong-password', $user->password));
    }

    public function test_duplicate_store_codes_and_names_are_rejected(): void
    {
        $admin = $this->makeAdmin();
        $this->makeStore('SF01', true, 'BW San Fernando');

        // same code, different case -> still a duplicate
        $this->actingAs($admin)->post('/admin/stores', ['name' => 'Another', 'code' => 'sf01', 'is_active' => true])->assertSessionHasErrors('code');
        $this->actingAs($admin)->post('/admin/stores', ['name' => 'BW San Fernando', 'code' => 'XX99', 'is_active' => true])->assertSessionHasErrors('name');

        $this->assertSame(1, Store::count());
    }

    public function test_store_fields_are_validated(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/admin/stores', ['name' => '', 'code' => '', 'is_active' => true])->assertSessionHasErrors(['name', 'code']);
        $this->actingAs($admin)->post('/admin/stores', ['name' => 'X', 'code' => 'bad code!', 'is_active' => true])->assertSessionHasErrors('code');
        $this->actingAs($admin)->post('/admin/stores', ['name' => 'X', 'code' => 'X1', 'email' => 'not-an-email', 'contact_number' => 'abc', 'is_active' => true])
            ->assertSessionHasErrors(['email', 'contact_number']);
        // a login needs all of name, email and password
        $this->actingAs($admin)->post('/admin/stores', ['name' => 'X', 'code' => 'X1', 'is_active' => true, 'user_email' => 'a@b.test'])
            ->assertSessionHasErrors(['user_name', 'user_password']);
        $this->actingAs($admin)->post('/admin/stores', ['name' => 'X', 'code' => 'X1', 'is_active' => true, 'user_name' => 'N', 'user_email' => 'a@b.test', 'user_password' => 'short'])
            ->assertSessionHasErrors('user_password');

        $this->assertSame(0, Store::count());
    }

    public function test_admin_can_assign_a_user_to_a_store_but_not_choose_the_role(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();

        $this->actingAs($admin)->post("/admin/stores/{$store->id}/users", [
            'name' => 'New Staff', 'email' => 'new@test.test', 'password' => 'long-enough-pw',
            'role' => 'admin', 'store_id' => 999, // must be ignored
        ])->assertRedirect();

        $u = User::where('email', 'new@test.test')->firstOrFail();
        $this->assertSame('store', $u->role);
        $this->assertSame($store->id, $u->store_id);
    }

    public function test_admin_can_edit_and_deactivate_a_store_without_touching_its_orders(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $order = $this->seedOrder($store, $user, 'posted', now()->toDateString(), [[$this->makeItems()['bw'], 5]]);

        $this->actingAs($admin)->put("/admin/stores/{$store->id}", ['name' => 'Renamed', 'code' => 'sf01', 'is_active' => true])->assertRedirect();
        $this->assertSame('Renamed', $store->fresh()->name);

        $this->actingAs($admin)->patch("/admin/stores/{$store->id}/status")->assertRedirect();
        $this->assertFalse($store->fresh()->is_active);
        $this->assertSame('posted', $order->fresh()->status);
        $this->assertSame(1, $order->lines()->count());

        $this->actingAs($admin)->patch("/admin/stores/{$store->id}/status");
        $this->assertTrue($store->fresh()->is_active);
    }

    public function test_a_store_created_by_the_admin_is_active_and_its_login_is_not_told_it_is_deactivated(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->post('/admin/stores', [
            'name' => 'BW San Luis', 'code' => 'FR001', 'is_active' => true,
            'user_name' => 'San Luis', 'user_email' => 'sanluis@example.com', 'user_password' => 'a-strong-password',
        ])->assertSessionHasNoErrors();
        auth()->logout();

        $this->assertTrue(Store::where('code', 'FR001')->firstOrFail()->is_active);

        $props = $this->post('/login', ['email' => 'sanluis@example.com', 'password' => 'a-strong-password'])->assertRedirect('/dashboard');
        $deadline = $this->get('/dashboard')->viewData('page')['props']['deadline'];

        $this->assertTrue($deadline['store_active'], 'the shared deadline info says the store is active');
        $this->assertSame('16:00', $deadline['time']);
        $this->assertNotNull($props);
    }

    public function test_the_admin_store_pages_never_override_the_shared_deadline_prop(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();
        $this->makeStoreUser($store);

        // The shared `deadline` prop only exists for store accounts; admin pages must not smuggle their own under that name
        // (that is what made the "Your store is deactivated" banner appear for an admin looking at a store).
        foreach (["/admin/stores/{$store->id}", '/admin/stores', '/admin/settings', '/admin/consolidated', '/admin/categories', '/dashboard', '/orders', '/retails'] as $url) {
            $props = $this->actingAs($admin)->get($url)->viewData('page')['props'];
            $this->assertNull($props['deadline'], "$url should leave the shared deadline empty for an admin");
            $this->assertSame('admin', $props['auth']['user']['role']);
        }

        $show = $this->actingAs($admin)->get("/admin/stores/{$store->id}")->viewData('page')['props'];
        $this->assertSame(['time', 'source'], array_keys($show['store_deadline']));
        $this->assertSame('16:00', $show['store_deadline']['time']);
    }

    public function test_a_deactivated_store_login_does_get_the_deactivated_notice(): void
    {
        $store = $this->makeStore('SF01', false);
        $user = $this->makeStoreUser($store);

        $deadline = $this->actingAs($user)->get('/dashboard')->viewData('page')['props']['deadline'];

        $this->assertFalse($deadline['store_active']);
        $this->assertFalse($deadline['open']);
    }

    public function test_admin_can_reset_a_store_login_password_but_nobody_else_can(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();
        $other = $this->makeStore('OT01', true, 'Other');
        $user = $this->makeStoreUser($store);
        $url = "/admin/stores/{$store->id}/users/{$user->id}/password";

        $this->actingAs($admin)->put($url, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->actingAs($admin)->put($url, ['password' => 'brand-new-pass', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->actingAs($admin)->put($url, ['password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])->assertSessionHasNoErrors();
        $this->assertTrue(password_verify('brand-new-pass', $user->fresh()->password));

        // wrong store, or an admin account -> 404
        $this->actingAs($admin)->put("/admin/stores/{$other->id}/users/{$user->id}/password", ['password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])->assertNotFound();
        $this->actingAs($admin)->put("/admin/stores/{$store->id}/users/{$admin->id}/password", ['password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])->assertNotFound();

        // a store login cannot reset anyone's password
        $this->actingAs($user)->put($url, ['password' => 'hijack-pass-1', 'password_confirmation' => 'hijack-pass-1'])->assertForbidden();
    }

    public function test_store_list_search_and_status_filter(): void
    {
        $admin = $this->makeAdmin();
        $this->makeStore('AAA1', true, 'Alpha');
        $this->makeStore('BBB2', false, 'Bravo');

        $names = fn ($r) => collect($r->viewData('page')['props']['stores']['data'])->pluck('name')->all();

        $this->assertSame(['Alpha'], $names($this->actingAs($admin)->get('/admin/stores?search=alp')));
        $this->assertSame(['Bravo'], $names($this->actingAs($admin)->get('/admin/stores?search=BBB')));
        $this->assertSame(['Bravo'], $names($this->actingAs($admin)->get('/admin/stores?status=inactive')));
        $this->assertSame(['Alpha', 'Bravo'], $names($this->actingAs($admin)->get('/admin/stores')));
    }
}

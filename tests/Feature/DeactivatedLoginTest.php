<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class DeactivatedLoginTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private function deactivate(User $admin, $store, User $user): void
    {
        $this->actingAs($admin)->put("/admin/stores/{$store->id}/users/{$user->id}/status", ['active' => false])->assertRedirect();
        auth()->logout();
    }

    public function test_a_deactivated_login_cannot_sign_in_and_is_told_why_only_with_the_right_password(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store, 'cashier@test.test');
        $this->deactivate($this->makeAdmin(), $store, $user);

        $this->assertNotNull($user->fresh()->deactivated_at);

        $right = $this->post('/login', ['email' => 'cashier@test.test', 'password' => 'secret-pass']);
        $right->assertSessionHasErrors(['email' => 'This account has been deactivated. Please contact an administrator.']);
        $this->assertGuest();

        // a wrong password gets the normal answer, so the form can't be used to find out who is deactivated
        $this->post('/login', ['email' => 'cashier@test.test', 'password' => 'nope'])->assertSessionHasErrors(['email' => "That email and password don't match. Try again."]);
        $this->assertGuest();
    }

    public function test_deactivating_signs_out_an_open_session_on_its_next_request(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $this->actingAs($user)->get('/orders')->assertOk();

        $user->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($user->fresh())->get('/orders')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_reactivating_lets_them_back_in(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store, 'cashier@test.test');
        $admin = $this->makeAdmin();
        $this->deactivate($admin, $store, $user);

        $this->actingAs($admin)->put("/admin/stores/{$store->id}/users/{$user->id}/status", ['active' => true])->assertRedirect();
        auth()->logout();

        $this->post('/login', ['email' => 'cashier@test.test', 'password' => 'secret-pass'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_only_admins_can_switch_a_login_and_only_within_its_own_store(): void
    {
        $a = $this->makeStore('AA01', true, 'Alpha');
        $b = $this->makeStore('BB01', true, 'Beta');
        $mine = $this->makeStoreUser($a, 'a@test.test');
        $theirs = $this->makeStoreUser($b, 'b@test.test');

        $this->actingAs($mine)->put("/admin/stores/{$a->id}/users/{$mine->id}/status", ['active' => false])->assertForbidden();

        $this->actingAs($this->makeAdmin())->put("/admin/stores/{$a->id}/users/{$theirs->id}/status", ['active' => false])->assertNotFound();
        $this->assertNull($theirs->fresh()->deactivated_at);
    }

    public function test_a_deactivated_login_gets_no_password_reset_link(): void
    {
        Mail::fake();
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store, 'cashier@test.test');
        $user->forceFill(['deactivated_at' => now()])->save();

        $this->post('/forgot-password', ['email' => 'cashier@test.test'])->assertRedirect();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'cashier@test.test']);
    }

    public function test_an_area_manager_can_be_deactivated_too(): void
    {
        $store = $this->makeStore();
        $admin = $this->makeAdmin();
        $manager = new User(['name' => 'Area One', 'email' => 'area@test.test', 'password' => 'secret-pass']);
        $manager->forceFill(['role' => User::AREA_MANAGER])->save();
        $manager->managedStores()->sync([$store->id]);

        $this->actingAs($admin)->put("/admin/area-managers/{$manager->id}/status", ['active' => false])->assertRedirect();
        $this->assertFalse($manager->fresh()->isActive());
        $this->assertTrue(ActivityLog::where('event', 'user_deactivated')->exists());
    }

    public function test_the_audit_log_records_sign_ins_failures_and_password_changes(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store, 'cashier@test.test');

        $this->post('/login', ['email' => 'cashier@test.test', 'password' => 'wrong']);
        $this->post('/login', ['email' => 'cashier@test.test', 'password' => 'secret-pass']);
        $this->put('/account/password', ['current_password' => 'secret-pass', 'password' => 'a-new-password', 'password_confirmation' => 'a-new-password']);
        $this->post('/logout');

        $this->assertSame(['login_failed', 'login', 'password_changed', 'logout'], ActivityLog::orderBy('id')->pluck('event')->all());
        $this->assertSame($user->id, ActivityLog::where('event', 'login')->value('user_id'));
        $this->assertNull(ActivityLog::where('event', 'login_failed')->value('user_id'));
        $this->assertSame('cashier@test.test', ActivityLog::where('event', 'login_failed')->value('subject'));
        $this->assertNotNull(ActivityLog::where('event', 'login')->value('ip'));

        // the log never holds a password
        $this->assertSame(0, ActivityLog::where('note', 'like', '%password%')->orWhere('subject', 'like', '%wrong%')->count());
    }

    public function test_the_audit_log_page_lists_and_filters_this_activity(): void
    {
        $store = $this->makeStore();
        $this->makeStoreUser($store, 'cashier@test.test');
        $admin = $this->makeAdmin();
        $this->post('/login', ['email' => 'cashier@test.test', 'password' => 'wrong']);
        $this->post('/login', ['email' => 'nobody@test.test', 'password' => 'wrong']);
        $this->actingAs($admin);

        $props = fn (string $q = '') => $this->get('/admin/audit-log'.$q)->assertOk()->viewData('page')['props'];

        $all = collect($props()['entries']['data']);
        $this->assertSame(['login_failed', 'login_failed'], $all->pluck('event')->all());
        $this->assertSame('Failed sign-in', $props()['event_labels']['login_failed']['label']);

        $this->assertCount(2, $props('?type=login_failed')['entries']['data']);
        $this->assertCount(0, $props('?type=login')['entries']['data']);

        $found = $props('?search=nobody')['entries']['data'];
        $this->assertCount(1, $found);
        $this->assertSame('nobody@test.test', $found[0]['subject']);
    }
}

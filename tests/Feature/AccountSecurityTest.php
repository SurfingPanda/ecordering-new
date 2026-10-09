<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    public function test_anyone_signed_in_can_change_their_own_password(): void
    {
        $user = $this->makeStoreUser($this->makeStore());
        $user->forceFill(['password' => Hash::make('welcome')])->save();

        $this->actingAs($user)->get('/account')->assertOk();

        // wrong current password, too short, mismatch, same as before
        $this->actingAs($user)->put('/account/password', ['current_password' => 'nope', 'password' => 'brand-new-1', 'password_confirmation' => 'brand-new-1'])->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put('/account/password', ['current_password' => 'welcome', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->actingAs($user)->put('/account/password', ['current_password' => 'welcome', 'password' => 'brand-new-1', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->actingAs($user)->put('/account/password', ['current_password' => 'welcome', 'password' => 'welcome', 'password_confirmation' => 'welcome'])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('welcome', $user->fresh()->password));

        $this->actingAs($user)->put('/account/password', ['current_password' => 'welcome', 'password' => 'brand-new-1', 'password_confirmation' => 'brand-new-1'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('brand-new-1', $user->fresh()->password));

        auth()->logout();
        $this->get('/account')->assertRedirect('/login'); // signed-out visitors can't reach it
    }

    public function test_five_wrong_passwords_lock_that_email_for_a_minute(): void
    {
        $user = $this->makeStoreUser($this->makeStore());
        $user->forceFill(['password' => Hash::make('welcome')])->save();
        RateLimiter::clear(strtolower($user->email).'|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }

        // even the right password is refused while locked
        $this->post('/login', ['email' => $user->email, 'password' => 'welcome'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertStringContainsString('Too many', session('errors')->first('email'));

        // another email isn't affected
        $other = $this->makeStoreUser($this->makeStore('OT01', true, 'Other'));
        $other->forceFill(['password' => Hash::make('welcome')])->save();
        $this->post('/login', ['email' => $other->email, 'password' => 'welcome'])->assertRedirect('/dashboard');
    }
}

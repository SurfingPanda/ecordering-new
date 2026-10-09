<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class CreateStoreLoginsTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    public function test_it_creates_a_login_only_for_stores_without_one_and_the_login_can_sign_in(): void
    {
        $with = $this->makeStore('AA01', true, 'BW Has Login');
        $existing = $this->makeStoreUser($with);
        $without = $this->makeStore('BW0011', true, 'BW WM Antipolo');

        $this->artisan('stores:create-logins')->assertSuccessful();

        $this->assertSame(1, $with->users()->count(), 'existing logins are not touched');
        $user = $without->users()->firstOrFail();
        $this->assertSame(['bw.wmantipolo', 'bw.wmantipolo@ecticketph.com', 'store'], [$user->name, $user->email, $user->role]);

        $this->post('/login', ['email' => 'bw.wmantipolo@ecticketph.com', 'password' => 'welcome'])->assertRedirect('/dashboard');

        // running it again creates nothing
        auth()->logout();
        $before = User::count();
        $this->artisan('stores:create-logins')->assertSuccessful();
        $this->assertSame($before, User::count());
    }
}

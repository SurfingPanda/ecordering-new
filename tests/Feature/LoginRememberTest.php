<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class LoginRememberTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private function loginCookies(bool $remember)
    {
        $user = $this->makeStoreUser($this->makeStore());
        $user->forceFill(['password' => bcrypt('secret-pass-1')])->save();

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass-1', 'remember' => $remember])->assertRedirect('/dashboard');

        return [$user, collect($response->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'))];
    }

    public function test_keep_me_signed_in_sets_a_long_lived_cookie_that_restores_the_login(): void
    {
        [$user, $cookie] = $this->loginCookies(true);

        $this->assertNotNull($cookie, 'remember cookie is issued');
        $this->assertGreaterThan(time() + 86400 * 300, $cookie->getExpiresTime());

        // the normal session is gone (expired / browser closed) but the remember cookie is still there
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        $this->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_without_keep_me_signed_in_no_remember_cookie_is_issued(): void
    {
        [, $cookie] = $this->loginCookies(false);
        $this->assertNull($cookie);
    }
}

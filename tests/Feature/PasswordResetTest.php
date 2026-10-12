<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    public function test_the_pages_open_for_signed_out_visitors_and_the_login_page_links_to_them(): void
    {
        $this->get('/forgot-password')->assertOk();
        $this->get('/reset-password/abc?email=a@b.test')->assertOk()->assertInertia(fn ($p) => $p->where('token', 'abc')->where('email', 'a@b.test'));
        $this->actingAs($this->makeAdmin())->get('/forgot-password')->assertRedirect(); // signed-in people have no use for it
    }

    public function test_a_known_email_gets_a_link_and_an_unknown_one_gets_the_same_answer(): void
    {
        Mail::fake();
        $admin = $this->makeAdmin('boss@bw.test');

        $known = $this->post('/forgot-password', ['email' => 'BOSS@bw.test']); // capitals don't matter
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@bw.test']);

        $known->assertSessionHasNoErrors();
        $this->assertSame(session('success'), $unknown->getSession()->get('success')); // nothing reveals which emails exist
        Mail::assertSent(ResetPasswordMail::class, 1);
        Mail::assertSent(ResetPasswordMail::class, fn ($m) => $m->hasTo('boss@bw.test') && str_contains($m->url, '/reset-password/') && str_contains($m->url, 'email=boss%40bw.test') && $m->minutes === 60);
    }

    public function test_the_email_is_checked_and_a_mail_failure_is_not_shown(): void
    {
        $this->post('/forgot-password', ['email' => 'not an email'])->assertSessionHasErrors('email');

        $this->makeAdmin('boss@bw.test');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));
        $this->post('/forgot-password', ['email' => 'boss@bw.test'])->assertSessionHasNoErrors(); // same friendly answer
    }

    public function test_a_valid_link_sets_the_new_password_once_and_signs_the_old_sessions_out(): void
    {
        $user = $this->makeStoreUser($this->makeStore('AA01'), 'shop@bw.test');
        config(['session.driver' => 'database']); // as in the real app, where sessions live in the database
        DB::table('sessions')->insert(['id' => 'old', 'user_id' => $user->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
        $token = Password::createToken($user);

        $this->post('/reset-password', ['token' => $token, 'email' => 'shop@bw.test', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertRedirect('/login')->assertSessionHas('success');

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());

        // the link can't be used a second time
        $this->post('/reset-password', ['token' => $token, 'email' => 'shop@bw.test', 'password' => 'another-pass-99', 'password_confirmation' => 'another-pass-99'])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));

        // and the new password signs in
        $this->post('/login', ['email' => 'shop@bw.test', 'password' => 'brand-new-pass'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_expired_or_weak_attempt_changes_nothing(): void
    {
        $user = $this->makeStoreUser($this->makeStore('AA01'), 'shop@bw.test');
        $old = $user->password;
        $token = Password::createToken($user);

        $this->post('/reset-password', ['token' => 'wrong', 'email' => 'shop@bw.test', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])->assertSessionHasErrors('email');
        $this->post('/reset-password', ['token' => $token, 'email' => 'shop@bw.test', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => $token, 'email' => 'shop@bw.test', 'password' => 'brand-new-pass', 'password_confirmation' => 'different-pass'])->assertSessionHasErrors('password');

        $this->travel(61)->minutes(); // past the 60 minutes
        $this->post('/reset-password', ['token' => $token, 'email' => 'shop@bw.test', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])->assertSessionHasErrors('email');

        $this->assertSame($old, $user->fresh()->password);
    }

    public function test_the_email_has_the_button_the_name_and_the_expiry(): void
    {
        $html = (new ResetPasswordMail('Arvin', 'https://bw.test/reset-password/tok?email=a%40b.test', 60))->render();

        $this->assertStringContainsString('Choose a new password', $html);
        $this->assertStringContainsString('Hi Arvin', $html);
        $this->assertStringContainsString('60 minutes', $html);
        $this->assertStringContainsString('href="https://bw.test/reset-password/tok?email=a%40b.test"', $html);
        $this->assertStringContainsString('<img src="data:image/png', $html);
    }

    public function test_asking_for_links_is_rate_limited(): void
    {
        Mail::fake();
        $this->makeAdmin('boss@bw.test');

        foreach (range(1, 5) as $i) {
            $this->post('/forgot-password', ['email' => 'boss@bw.test'])->assertSessionHasNoErrors();
        }
        $this->post('/forgot-password', ['email' => 'boss@bw.test'])->assertStatus(429);
        Mail::assertSent(ResetPasswordMail::class, 1); // and the account itself only gets one a minute
    }
}

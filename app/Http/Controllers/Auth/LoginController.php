<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('auth/login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // 5 wrong passwords for the same email from the same device, then wait a minute (stops password guessing)
        $throttleKey = Str::lower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            Activity::log('login_blocked', $credentials['email'], 'Too many wrong passwords from this device');

            return back()
                ->withErrors(['email' => "Too many sign-in attempts. Please wait {$seconds} seconds and try again."])
                ->onlyInput('email');
        }

        // a deactivated login is told so only once it proves it knows the password (so the form can't be used to find out who exists)
        $known = User::where('email', Str::lower($credentials['email']))->first();
        if ($known && ! $known->isActive() && Hash::check($credentials['password'], $known->password)) {
            Activity::log('login_deactivated', Activity::person($known), null, $known);

            return back()
                ->withErrors(['email' => 'This account has been deactivated. Please contact an administrator.'])
                ->onlyInput('email');
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);
            Activity::log('login_failed', $credentials['email']);

            return back()
                ->withErrors(['email' => "That email and password don't match. Try again."])
                ->onlyInput('email');
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        Activity::log('login', Activity::person($request->user()), null, $request->user(), $request->user()->store_id);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            Activity::log('logout', Activity::person($user), null, $user, $user->store_id);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

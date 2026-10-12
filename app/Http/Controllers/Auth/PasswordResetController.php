<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** "Forgot password": a one-time link is emailed to the account, and opens a page to choose a new password. */
class PasswordResetController extends Controller
{
    public function showRequest(): Response
    {
        return Inertia::render('auth/forgot-password');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        // The answer is always the same, so nobody can use this form to find out which emails have an account.
        // (Laravel also refuses to send a second link to the same account within a minute.)
        Password::sendResetLink(['email' => Str::lower($data['email'])]);

        return back()->with('success', 'If that email belongs to an account, we have sent a link to choose a new password. It works for '.config('auth.passwords.users.expire').' minutes. If you do not see it, check your spam folder.');
    }

    public function showReset(Request $request, string $token): Response
    {
        return Inertia::render('auth/reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ], [
            'password.min' => 'Use at least 8 characters.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $status = Password::reset(
            ['email' => Str::lower($data['email']), 'password' => $data['password'], 'token' => $data['token']],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

                // anyone signed in with the old password (a lost phone, a shared tablet) is signed out
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                }

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'This link is not valid any more. It may have expired or already been used. Please request a new one.']);
        }

        return to_route('login')->with('success', 'Your password has been changed. You can sign in with it now.');
    }
}

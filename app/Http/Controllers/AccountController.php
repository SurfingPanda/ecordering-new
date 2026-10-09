<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** A signed-in user's own account: change your own password (optional, never forced). */
class AccountController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('account');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed', 'different:current_password'],
        ], [
            'current_password.current_password' => 'That isn’t your current password.',
            'password.min' => 'Use at least 8 characters.',
            'password.confirmed' => 'The two new passwords don’t match.',
            'password.different' => 'Choose a password different from the current one.',
        ]);

        $user = $request->user();
        $user->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();

        return back()->with('success', 'Your password was changed.');
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: ->middleware('role:admin') or ->middleware('role:store').
 * This is the server-side enforcement; hiding links in the UI is never the only protection.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user && in_array($user->role, $roles, true), 403, 'You do not have access to this page.');

        return $next($request);
    }
}

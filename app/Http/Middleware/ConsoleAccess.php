<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every console endpoint: signed in, not suspended, and (for company members)
 * the company not suspended. A suspended account is signed out on its next
 * request rather than waiting for the session to expire.
 */
class ConsoleAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Please sign in.'], 401);
        }

        if ($user->status !== 'active' || $user->company?->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return response()->json(['message' => 'This account has been suspended.'], 401);
        }

        if ($user->last_active_at === null || $user->last_active_at->lt(now()->subMinutes(5))) {
            $user->forceFill(['last_active_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}

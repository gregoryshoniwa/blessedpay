<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The company-facing API authenticates with "Authorization: Bearer sk_test_…"
 * or "sk_live_…". The key decides both the company and the mode.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = ($token = $request->bearerToken()) ? ApiKey::findByPlain($token) : null;

        if (! $key || $key->company->isSuspended()) {
            return response()->json([
                'error' => ['code' => 'unauthorized', 'message' => 'Missing, wrong or revoked API key.'],
            ], 401);
        }

        // Once a minute is enough to show "last used" without a write per request.
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_key', $key);
        $request->attributes->set('company', $key->company);
        $request->attributes->set('mode', $key->mode);

        return $next($request);
    }
}

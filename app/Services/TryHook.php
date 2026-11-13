<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * The "Try it" page's stand-in for a company's own server: an address that
 * simply keeps the callbacks sent to it for a couple of hours, so the page can
 * show exactly what a real server would receive (headers, body, signature).
 *
 * The address is /try/hook/{token}. The token is long and random and made by
 * the page that will read it back.
 */
final class TryHook
{
    private const KEEP_MINUTES = 120;

    private const MAX_ENTRIES = 10;

    public static function validToken(?string $token): bool
    {
        return is_string($token) && preg_match('/^[a-f0-9]{32,64}$/', $token) === 1;
    }

    /** The token, when $url is one of our own Try-it addresses. */
    public static function accepts(?string $url): ?string
    {
        $prefix = rtrim(url('/try/hook'), '/').'/';
        $token = is_string($url) && str_starts_with($url, $prefix) ? substr($url, strlen($prefix)) : null;

        return self::validToken($token) ? $token : null;
    }

    public static function record(string $token, array $headers, string $body): void
    {
        $entries = self::read($token);
        $entries[] = ['at' => now()->toIso8601String(), 'headers' => $headers, 'body' => mb_substr($body, 0, 20000)];

        Cache::put('tryhook:'.$token, array_slice($entries, -self::MAX_ENTRIES), now()->addMinutes(self::KEEP_MINUTES));
    }

    public static function read(string $token): array
    {
        return (array) Cache::get('tryhook:'.$token, []);
    }
}

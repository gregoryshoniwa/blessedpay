<?php

namespace App\Support;

use App\Models\Payment;

/**
 * Which sites may show a payment's checkout inside a pop-up frame.
 *
 * Always: the site the shopper will be returned to, and the company's own
 * website. Plus any extra origins the company lists. Everything else gets the
 * full-page checkout — the browser refuses to draw the frame.
 */
final class EmbedOrigin
{
    /** A bare origin (scheme://host[:port]) or null. */
    public static function clean(?string $origin): ?string
    {
        return is_string($origin) && preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#i', $origin) ? strtolower($origin) : null;
    }

    public static function of(?string $url): ?string
    {
        $parts = parse_url((string) $url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return self::clean($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''));
    }

    public static function allowed(Payment $payment, ?string $origin): ?string
    {
        $origin = self::clean($origin);

        if ($origin === null) {
            return null;
        }

        $list = array_filter([
            self::of($payment->return_url),
            self::of($payment->company->website),
            ...array_map(self::clean(...), (array) $payment->company->embed_origins),
        ]);

        return in_array($origin, $list, true) ? $origin : null;
    }
}

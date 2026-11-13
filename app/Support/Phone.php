<?php

namespace App\Support;

final class Phone
{
    /**
     * A Zimbabwe mobile number in the form the bank wants (2637XXXXXXXX), from
     * however a person types it: 077 123 4567, 771234567, +263 77 123 4567.
     * Null when it is not one.
     */
    public static function zimbabwe(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);

        $national = match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '263') => substr($digits, 3),
            strlen($digits) === 10 && str_starts_with($digits, '0') => substr($digits, 1),
            strlen($digits) === 9 => $digits,
            default => null,
        };

        return $national !== null && $national[0] === '7' ? '263'.$national : null;
    }

    /** For showing back to the shopper: 077 *** 4567. */
    public static function masked(?string $phone): ?string
    {
        return $phone ? '0'.substr($phone, 3, 2).' *** '.substr($phone, -4) : null;
    }
}

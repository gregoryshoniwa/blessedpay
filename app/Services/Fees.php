<?php

namespace App\Services;

/**
 * The bank's processing fee, as told to BLESSLUXE by the bank (Nov 2026):
 * cards 1% with a US$0.20 minimum; EcoCash and bank account 1%. The bank takes
 * it out of what the company receives.
 *
 * A company either absorbs it, or passes it to the shopper. When passed on,
 * the shopper is charged enough that, after the bank takes its cut, the
 * company still receives the full amount (so the fee is worked out on the
 * total, not on the goods amount).
 *
 * One rate for every method keeps the total the shopper sees fixed before
 * they pick how to pay; the card minimum is the only exception and is
 * covered by using it as the floor.
 */
final class Fees
{
    public static function percent(): float
    {
        return (float) config('blessedpay.fee.percent', 1.0);
    }

    public static function minimum(): float
    {
        return (float) config('blessedpay.fee.minimum', 0.20);
    }

    /** What to add on top of $amount when the shopper pays the fee. */
    public static function onTop(string|float $amount): string
    {
        $amount = (float) $amount;
        $rate = self::percent() / 100;

        if ($rate <= 0 || $rate >= 1) {
            return '0.00';
        }

        $fee = max($amount / (1 - $rate) - $amount, self::minimum());

        return number_format(ceil(round($fee * 100, 4)) / 100, 2, '.', '');
    }

    /** What the bank is expected to keep from a charge of $charged. */
    public static function bankCut(string|float $charged): string
    {
        return number_format(max((float) $charged * self::percent() / 100, self::minimum()), 2, '.', '');
    }
}

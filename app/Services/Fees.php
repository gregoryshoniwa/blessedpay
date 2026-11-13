<?php

namespace App\Services;

use App\Models\Setting;

/**
 * What a payment costs, and who gets what.
 *
 * Two charges, both set by staff on the console's Charges page (kept in
 * `settings`, per currency):
 *
 *  - the BANK's fee, per way of paying, exactly as the bank applies it — so our
 *    figures tally with the bank's. Told to BLESSLUXE by the bank (Nov 2026):
 *    cards 1% with a US$0.20 minimum; EcoCash and bank account 1%, no minimum.
 *    The bank keeps it from what it credits.
 *  - the GATEWAY margin, BlessedPay's own: a percentage of the order amount
 *    plus a fixed amount. It is what the reports call gateway profit.
 *
 * Who bears them is the company's choice (`companies.fee_bearer`):
 *
 *  customer  both are added on top. The shopper pays just enough that, after
 *            the bank keeps its fee and the margin is set aside, the company
 *            is left with the full order amount. The fee depends on the way of
 *            paying, so it is fixed when the shopper chooses one.
 *  merchant  the shopper pays the order amount; the bank's fee and the margin
 *            come out of what the company receives.
 *
 * Everything is in whole cents and always adds up:
 *   shopper pays = order amount + fee on top
 *   bank credits = shopper pays − bank fee        ← compare with the bank statement
 *   company keeps = bank credits − margin
 */
final class Fees
{
    public const CURRENCIES = ['USD', 'ZWG'];

    public const METHODS = ['card', 'ecocash', 'bank'];

    /** @return array{bank: array<string, array{percent: float, minimum: float}>, margin: array{percent: float, fixed: float}} */
    public static function rules(string $currency = 'USD'): array
    {
        $saved = Setting::read('charges', [])[strtoupper($currency)] ?? [];
        $percent = (float) config('blessedpay.fee.percent', 1.0);
        $defaults = [
            'card' => ['percent' => $percent, 'minimum' => (float) config('blessedpay.fee.minimum', 0.20)],
            'ecocash' => ['percent' => $percent, 'minimum' => 0.0],
            'bank' => ['percent' => $percent, 'minimum' => 0.0],
        ];

        $bank = [];
        foreach (self::METHODS as $method) {
            $bank[$method] = [
                'percent' => (float) ($saved['bank'][$method]['percent'] ?? $defaults[$method]['percent']),
                'minimum' => (float) ($saved['bank'][$method]['minimum'] ?? $defaults[$method]['minimum']),
            ];
        }

        return [
            'bank' => $bank,
            'margin' => ['percent' => (float) ($saved['margin']['percent'] ?? 0), 'fixed' => (float) ($saved['margin']['fixed'] ?? 0)],
        ];
    }

    /** What the bank keeps from a charge of $charged made with $method (the card rule when the method is unknown). */
    public static function bankCut(string|float $charged, string $currency = 'USD', ?string $method = null): string
    {
        $rule = self::rules($currency)['bank'][$method ?? 'card'] ?? self::rules($currency)['bank']['card'];

        return self::money(max(round((float) $charged * $rule['percent'] / 100, 2), $rule['minimum']));
    }

    /** The gateway's margin on an order of $amount. */
    public static function margin(string|float $amount, string $currency = 'USD'): string
    {
        $rule = self::rules($currency)['margin'];

        return self::money(round((float) $amount * $rule['percent'] / 100 + $rule['fixed'], 2));
    }

    /**
     * Everything about one payment of $amount made with $method.
     *
     * @return array{rate:?string, amount:string, fee:string, charged:string, bank_fee:string, bank_credits:string, margin:string, company_keeps:string}
     */
    public static function quote(string|float $amount, string $currency, string $method, string $bearer): array
    {
        $amount = round((float) $amount, 2);
        $rule = self::rules($currency)['bank'][$method] ?? self::rules($currency)['bank']['card'];
        $rate = min($rule['percent'], 99) / 100;
        $margin = (float) self::margin($amount, $currency);

        if ($bearer === 'customer') {
            // Enough that amount + margin is left once the bank has taken its cut…
            $charged = ceil(round(($amount + $margin) / (1 - $rate) * 100, 4)) / 100;
            // …or, when that cut is below the bank's minimum, the minimum on top.
            if (round($charged * $rate, 2) < $rule['minimum']) {
                $charged = $amount + $margin + $rule['minimum'];
            }
            // The bank rounds its cut to the cent, so a cent less may still be enough: never charge a cent more than needed.
            $left = fn (float $c) => round($c - (float) self::bankCut($c, $currency, $method), 2);
            for ($i = 0; $i < 3 && $charged - 0.01 >= $amount && $left(round($charged - 0.01, 2)) >= round($amount + $margin, 2); $i++) {
                $charged = round($charged - 0.01, 2);
            }
        } else {
            $charged = $amount;
        }

        $bankFee = (float) self::bankCut($charged, $currency, $method);
        // On top, whatever is left of the fee after the bank's cut is the margin (so a cent of rounding never goes missing).
        $margin = $bearer === 'customer' ? max($charged - $amount - $bankFee, 0) : $margin;

        return [
            'rate' => $bearer === 'customer' ? self::rateLabel($currency, $method, $charged) : null,
            'amount' => self::money($amount),
            'fee' => self::money($charged - $amount),
            'charged' => self::money($charged),
            'bank_fee' => self::money($bankFee),
            'bank_credits' => self::money($charged - $bankFee),
            'margin' => self::money($margin),
            'company_keeps' => self::money($charged - $bankFee - $margin),
        ];
    }

    /**
     * The rule behind a fee on top, in a few characters for the checkout:
     * "1.5%", "0.5% + USD 0.20" or "USD 0.20". The bank's percentage counts
     * unless its minimum is what was actually charged, in which case the
     * minimum is shown as the fixed part — so the label always describes the
     * fee the shopper is looking at.
     */
    public static function rateLabel(string $currency, string $method, float $charged): ?string
    {
        $rules = self::rules($currency);
        $bank = $rules['bank'][$method] ?? $rules['bank']['card'];
        $minimumApplies = $bank['minimum'] > 0 && round($charged * $bank['percent'] / 100, 2) <= $bank['minimum'];

        $percent = $rules['margin']['percent'] + ($minimumApplies ? 0 : $bank['percent']);
        $fixed = $rules['margin']['fixed'] + ($minimumApplies ? $bank['minimum'] : 0);
        $parts = array_filter([
            $percent > 0 ? rtrim(rtrim(number_format($percent, 3, '.', ''), '0'), '.').'%' : null,
            $fixed > 0 ? strtoupper($currency).' '.self::money($fixed) : null,
        ]);

        return $parts ? implode(' + ', $parts) : null;
    }

    public static function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}

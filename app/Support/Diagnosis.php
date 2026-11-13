<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\PaymentEvent;

/**
 * Reads a payment's audit trail and says, in one line, where it stands and —
 * if something stopped it — whose side that happened on:
 *
 *   bank      the bank refused, declined, failed or could not be reached
 *   system    BlessedPay itself broke
 *   merchant  the company's system (its callback address) is the problem
 *   shopper   the shopper cancelled, walked away or has not acted yet
 *   none      nothing went wrong
 *
 * It only reads what the trail already holds: the `error` lines and whose
 * step each one was.
 */
final class Diagnosis
{
    private const HEADLINES = [
        'bank' => 'Stopped at the bank',
        'system' => 'Stopped inside BlessedPay',
        'merchant' => "The problem is on the company's side",
        'shopper' => 'The shopper did not complete it',
    ];

    /** @return array{where:string, title:string, detail:string, event_id:?int} */
    public static function of(Payment $payment): array
    {
        $events = $payment->events;
        // The last thing that went wrong while trying to pay (callback trouble is a separate matter).
        $error = $events->last(fn (PaymentEvent $e) => $e->level === 'error' && ! str_starts_with($e->type, 'callback.') && ! str_starts_with($e->type, 'payment.'));
        $callback = $events->last(fn (PaymentEvent $e) => str_starts_with($e->type, 'callback.'));

        if ($payment->status === 'paid') {
            if ($twice = $events->firstWhere('type', 'attempt.paid_twice')) {
                return self::say('bank', 'Paid twice: one payment must be refunded', $twice->summary, $twice);
            }
            if ($payment->callback_status === 'failed') {
                return self::say('merchant', "Paid, but the company's system has not accepted the news",
                    'The money moved. '.($callback?->summary ?? 'The callback failed.').' Fix the callback address, then use "Send callback again".', $callback);
            }

            return self::say('none', 'Paid. Every step worked', $error
                ? 'An earlier try failed ('.$error->summary.'), then the payment went through.'
                : 'The bank confirmed the payment'.($payment->callback_url ? ' and the company\'s system was told.' : '.'));
        }

        if ($payment->status === 'pending') {
            if ($payment->attempts->contains('status', 'pending')) {
                return self::say('none', 'In progress: waiting for the shopper and the bank', 'A try is open at the bank. Nothing has gone wrong so far.');
            }
            if ($error) {
                return self::say($error->actor, 'Still open. The last try was '.lcfirst(self::HEADLINES[$error->actor] ?? 'stopped'), $error->summary.' The shopper can try again until the payment runs out of time.', $error);
            }

            return self::say('shopper', 'Waiting for the shopper', $events->contains('type', 'checkout.opened')
                ? 'The checkout was opened but no way of paying has been chosen yet.'
                : 'The shopper has not opened the checkout yet. Check that the company\'s system sent them to the payment page.');
        }

        if ($payment->status === 'cancelled') {
            return self::say('shopper', 'The shopper cancelled', $error
                ? 'Before cancelling, a try failed: '.$error->summary
                : 'No try failed. The shopper chose to leave.');
        }

        // failed
        if ($error) {
            return self::say($error->actor, self::HEADLINES[$error->actor] ?? 'The payment failed', $error->summary, $error);
        }

        return self::say('shopper', self::HEADLINES['shopper'], $payment->attempts->isEmpty()
            ? 'The payment ran out of time before any way of paying was chosen. Nothing reached the bank.'
            : 'The payment ran out of time. No error came from the bank or from BlessedPay.');
    }

    private static function say(string $where, string $title, string $detail, ?PaymentEvent $event = null): array
    {
        return ['where' => $where, 'title' => $title, 'detail' => $detail, 'event_id' => $event?->id];
    }
}

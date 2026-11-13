<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\Gateways\Gateways;
use App\Services\Gateways\GatewayUnavailable;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creating a payment, trying to pay it, and settling what happened.
 *
 * A payment can be TRIED several times (a declined card, then EcoCash) but is
 * paid at most once. Everything AFTER "paid" (orders, stock, Bees, receipts)
 * belongs to the company's own system, which hears about it through
 * CallbackSender — never put order logic here.
 */
class PaymentService
{
    public function __construct(private CallbackSender $callbacks) {}

    /**
     * @param  array{amount:string, currency:string, reference:string, return_url:string, callback_url?:?string, description?:?string, metadata?:?array, customer?:?array}  $data
     * @return array{0: Payment, 1: bool} [the payment, whether it was created by this call]
     *
     * @throws IdempotencyConflict when the key was already used for a different payment
     */
    public function create(Company $company, string $mode, array $data, ?string $idempotencyKey): array
    {
        if ($idempotencyKey !== null && ($existing = $this->findByKey($company, $mode, $idempotencyKey))) {
            return [$this->sameOrFail($existing, $data), false];
        }

        try {
            $payment = Payment::create([
                'company_id' => $company->id,
                'mode' => $mode,
                'reference' => $data['reference'],
                'amount' => $data['amount'],
                // The shopper pays the bank's fee on top only when the company has chosen that.
                'fee' => $company->fee_bearer === 'customer' ? Fees::onTop($data['amount']) : '0.00',
                'currency' => strtoupper($data['currency']),
                'status' => 'pending',
                'gateway' => Gateways::forMode($mode)->id(),
                'payer_phone' => Phone::zimbabwe($data['customer']['phone'] ?? null),
                'description' => $data['description'] ?? null,
                'return_url' => $data['return_url'],
                'callback_url' => $data['callback_url'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $data['metadata'] ?? null,
                'expires_at' => now()->addMinutes((int) config('blessedpay.payment_minutes', 30)),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two requests with the same key raced; the other one won.
            return [$this->sameOrFail($this->findByKey($company, $mode, $idempotencyKey), $data), false];
        }

        $payment->log('payment.created', "The company's system asked for {$payment->currency} {$payment->amount} ({$mode} key)", [
            'reference' => $payment->reference,
            'amount' => $payment->amount,
            'fee_on_top' => $payment->fee,
            'currency' => $payment->currency,
            'description' => $payment->description,
            'return_url' => $payment->return_url,
            'callback_url' => $payment->callback_url,
            'idempotency_key' => $idempotencyKey,
            'bank' => $payment->gateway,
            'open_until' => $payment->expires_at?->toIso8601String(),
        ], actor: 'merchant');

        return [$payment, true];
    }

    /**
     * Begin one try at paying. Returns what the checkout does next
     * (see BankGateway::begin).
     *
     * @throws GatewayUnavailable with a message that is safe to show the shopper
     */
    public function startAttempt(Payment $payment, string $method, ?string $phone): array
    {
        $gateway = Gateways::get($payment->gateway);
        $offered = collect($gateway->methods($payment))->firstWhere('id', $method);
        $phone = $phone ?: $payment->payer_phone;

        if ($payment->isFinal() || $payment->expires_at?->isPast()) {
            throw new GatewayUnavailable('This payment is no longer open.');
        }
        if (! $offered) {
            throw new GatewayUnavailable('That way of paying is not available for this payment.');
        }
        if (in_array('phone', $offered['needs'], true) && ! $phone) {
            throw new GatewayUnavailable('Enter a Zimbabwe mobile number, for example 077 123 4567.');
        }

        $attempt = DB::transaction(function () use ($payment, $method, $phone) {
            Payment::whereKey($payment->id)->lockForUpdate()->first();

            // One try at a time. An abandoned try is still watched (see refresh):
            // a shopper can approve an old EcoCash prompt after moving on.
            $payment->attempts()->where('status', 'pending')->update(['status' => 'abandoned']);
            $number = $payment->attempts()->count() + 1;

            return $payment->attempts()->create([
                'method' => $method,
                'status' => 'pending',
                'reference' => 'BP'.strtoupper(substr($payment->id, -12)).'-'.$number,
                'phone' => $phone,
                'amount' => $payment->charged(),
            ]);
        });

        $payment->log('attempt.started', 'The shopper chose '.$attempt->label(), [
            'our_reference' => $attempt->reference,
            'amount' => $attempt->amount,
            'phone' => $attempt->phone,
        ], actor: 'shopper', attempt: $attempt);

        if ($gateway->beginsSlowly($method)) {
            // The bank does not answer until the shopper does. Tell the browser
            // to wait, then make the call once the response has gone.
            $ran = false;
            app()->terminating(function () use ($attempt, &$ran) {
                // Once only: on a long-lived server this callback can outlive the request that made it.
                if ($ran || ! $attempt->fresh()?->isPending() || $attempt->fresh()->gateway_uid !== null) {
                    return;
                }
                $ran = true;
                @set_time_limit(200);
                try {
                    $this->begin($attempt);
                } catch (GatewayUnavailable) {
                    // already written to the trail by begin()
                }
            });

            return ['kind' => 'wait'];
        }

        return $this->begin($attempt);
    }

    /** Make the bank call for a try and record how it went. */
    private function begin(PaymentAttempt $attempt): array
    {
        try {
            $step = Gateways::get($attempt->payment->gateway)->begin($attempt);
        } catch (GatewayUnavailable $e) {
            $this->apply($attempt, ['status' => 'failed', 'error' => $e->getMessage(), 'actor' => 'bank']);

            throw $e;
        } catch (Throwable $e) {
            // Our own code broke, not the bank. Say so in the trail, so nobody blames the bank for it.
            report($e);
            $attempt->payment->log('system.error', 'BlessedPay hit an error while starting '.$attempt->label(), [
                'error' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 500),
                'where' => basename($e->getFile()).':'.$e->getLine(),
            ], actor: 'system', level: 'error', attempt: $attempt);
            $this->apply($attempt, ['status' => 'failed', 'error' => 'Something went wrong on our side. Please try again.', 'actor' => 'system']);

            throw new GatewayUnavailable('Something went wrong on our side. Please try again.', previous: $e);
        }

        if (isset($step['result'])) {
            $this->apply($attempt, $step['result']);
            unset($step['result']);
        }

        return $step;
    }

    /** Record what the bank said about a try. Safe to call twice with the same news. */
    public function apply(PaymentAttempt $attempt, array $result): void
    {
        $attempt->refresh();
        $attempt->forceFill(['checked_at' => now()])->save();

        if (in_array($attempt->status, ['paid', 'failed'], true) || $result['status'] === 'pending') {
            return;
        }

        $payment = $attempt->payment;

        if ($result['status'] === 'failed') {
            $attempt->forceFill(['status' => 'failed', 'error' => $result['error'] ?? 'The payment did not go through.'])->save();
            $payment->log('attempt.failed', $attempt->label().' did not go through: '.$attempt->error, $result['raw'] ?? null,
                actor: $result['actor'] ?? 'bank', level: 'error', attempt: $attempt);

            return;
        }

        $attempt->forceFill(['status' => 'paid', 'error' => null])->save();

        if ($payment->fresh()->status === 'paid') {
            // Two tries were both approved. The shopper has paid twice: staff must refund one.
            $payment->log('attempt.paid_twice', 'A second try ('.$attempt->label().", {$attempt->reference}) was ALSO paid. Refund needed.", $result['raw'] ?? null,
                actor: 'bank', level: 'error', attempt: $attempt);
            report(new \RuntimeException("Payment {$payment->id} was paid twice (attempt {$attempt->reference})."));

            return;
        }

        $this->settle($payment, 'paid', [
            'gateway_reference' => $result['gateway_reference'] ?? $attempt->gateway_uid,
            'method' => $attempt->method,
            'raw' => $result['raw'] ?? null,
            'attempt' => $attempt,
        ]);
    }

    /**
     * Record an outcome. Safe to call twice with the same news, and once a
     * payment is paid it stays paid whatever arrives later.
     */
    public function settle(Payment $payment, string $status, array $details = []): Payment
    {
        $changed = DB::transaction(function () use ($payment, $status, $details) {
            $fresh = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($fresh->isFinal()) {
                // "Paid" beats an earlier cancel or expiry: the money really moved.
                if (! ($status === 'paid' && $fresh->status !== 'paid')) {
                    if ($fresh->status !== $status) {
                        $fresh->log('status.ignored', "Ignored late \"{$status}\": payment is already {$fresh->status}", $details['raw'] ?? null, level: 'warn');
                    }

                    return false;
                }
                $fresh->log('status.corrected', "Was {$fresh->status}, but the bank confirms it was paid", level: 'warn');
            }

            if (! in_array($status, Payment::FINAL, true)) {
                return false; // still pending: nothing to record
            }

            $fresh->forceFill([
                'status' => $status,
                'gateway_reference' => $details['gateway_reference'] ?? $fresh->gateway_reference,
                'method' => $details['method'] ?? $fresh->method,
                'paid_at' => $status === 'paid' ? now() : null,
                'finished_at' => now(),
            ])->save();

            $summary = $details['summary'] ?? 'Payment '.$status.($status === 'paid' && $fresh->method ? " by {$fresh->method}" : '');
            $fresh->log("payment.{$status}", $summary, $details['raw'] ?? null,
                actor: $details['actor'] ?? 'system',
                level: match ($status) {
                    'paid' => 'ok', 'cancelled' => 'warn', default => 'error'
                },
                attempt: $details['attempt'] ?? null);

            return true;
        });

        $payment->refresh();

        if ($changed) {
            $this->callbacks->send($payment);
        }

        return $payment;
    }

    /**
     * Ask the bank about every try that could still turn into money, then
     * close the payment if its time has run out.
     */
    public function refresh(Payment $payment, int $minSeconds = 3): Payment
    {
        $gateway = Gateways::get($payment->gateway);

        $watch = $payment->attempts()
            ->where(fn ($q) => $q->where('status', 'pending')
                ->orWhere(fn ($w) => $w->where('status', 'abandoned')->where('created_at', '>=', now()->subHours(2))))
            ->get();

        foreach ($watch as $attempt) {
            // Don't hammer the bank: the checkout asks every few seconds.
            if ($attempt->checked_at?->gt(now()->subSeconds($minSeconds))) {
                continue;
            }

            if ($result = $gateway->check($attempt)) {
                $this->apply($attempt, $result);
            }
        }

        $payment->refresh();

        if (! $payment->isFinal() && $payment->expires_at?->isPast() && ! $payment->attempts()->where('status', 'pending')->exists()) {
            $payment = $this->settle($payment, 'failed', [
                'summary' => 'Payment expired: it was not completed in time',
                // Out of time with nothing failing is the shopper walking away, not a fault.
                'actor' => $payment->attempts()->exists() ? 'system' : 'shopper',
            ]);
        }

        return $payment;
    }

    /** The shopper gave up. A try already at the bank is still watched. */
    public function cancel(Payment $payment): Payment
    {
        if ($payment->isFinal()) {
            return $payment;
        }

        $payment->attempts()->where('status', 'pending')->update(['status' => 'abandoned']);

        return $this->settle($payment, 'cancelled', ['summary' => 'Cancelled by the shopper', 'actor' => 'shopper']);
    }

    /** Where the shopper goes when we are finished with them. */
    public function returnUrl(Payment $payment): string
    {
        $query = http_build_query([
            'payment_id' => $payment->publicId(),
            'reference' => $payment->reference,
            'status' => $payment->status,
        ]);

        return $payment->return_url.(str_contains($payment->return_url, '?') ? '&' : '?').$query;
    }

    private function findByKey(Company $company, string $mode, string $key): ?Payment
    {
        return Payment::where('company_id', $company->id)->where('mode', $mode)->where('idempotency_key', $key)->first();
    }

    private function sameOrFail(Payment $existing, array $data): Payment
    {
        $same = number_format((float) $existing->amount, 2, '.', '') === number_format((float) $data['amount'], 2, '.', '')
            && $existing->currency === strtoupper($data['currency'])
            && $existing->reference === $data['reference'];

        return $same ? $existing : throw new IdempotencyConflict;
    }
}

<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Payment;
use App\Services\Gateways\Gateways;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creating a payment, and settling what happened to it. Everything AFTER
 * "paid" (orders, stock, Bees, receipts) belongs to the company's own system,
 * which hears about it through CallbackSender — never put order logic here.
 */
class PaymentService
{
    public function __construct(private CallbackSender $callbacks) {}

    /**
     * @param  array{amount:string, currency:string, reference:string, return_url:string, callback_url?:?string, description?:?string, metadata?:?array}  $data
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
                'currency' => strtoupper($data['currency']),
                'status' => 'pending',
                'gateway' => Gateways::forMode($mode)->id(),
                'description' => $data['description'] ?? null,
                'return_url' => $data['return_url'],
                'callback_url' => $data['callback_url'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $data['metadata'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two requests with the same key raced; the other one won.
            return [$this->sameOrFail($this->findByKey($company, $mode, $idempotencyKey), $data), false];
        }

        $payment->log('payment.created', "Payment created for {$payment->currency} {$payment->amount}", [
            'reference' => $payment->reference,
            'idempotency_key' => $idempotencyKey,
        ]);

        return [$payment, true];
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
                if ($fresh->status !== $status) {
                    $fresh->log('status.ignored', "Ignored late \"{$status}\": payment is already {$fresh->status}", $details['raw'] ?? null);
                }

                return false;
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

            $fresh->log("payment.{$status}", 'Payment '.$status.($fresh->method ? " by {$fresh->method}" : ''), $details['raw'] ?? null);

            return true;
        });

        $payment->refresh();

        if ($changed) {
            $this->callbacks->send($payment);
        }

        return $payment;
    }

    /** Ask the processor about a pending payment and settle it if there is news. */
    public function refresh(Payment $payment): Payment
    {
        if ($payment->isFinal()) {
            return $payment;
        }

        $news = Gateways::get($payment->gateway)->refresh($payment);

        return $news ? $this->settle($payment, $news['status'], $news) : $payment;
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

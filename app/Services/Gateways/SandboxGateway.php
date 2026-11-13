<?php

namespace App\Services\Gateways;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\Gateways\Contracts\BankGateway;

/**
 * A pretend bank for test-mode keys. It offers the same ways of paying as the
 * real bank, but instead of a card form or a phone prompt the checkout shows
 * Approve / Decline buttons — so the whole flow (pop-up, outcome, signed
 * callback, return) can be exercised without real money.
 */
class SandboxGateway implements BankGateway
{
    public function id(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox (test payments)';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function methods(Payment $payment): array
    {
        return StewardPayGateway::methodList($payment->currency);
    }

    public function beginsSlowly(string $method): bool
    {
        return false;
    }

    public function begin(PaymentAttempt $attempt): array
    {
        $attempt->forceFill(['gateway_uid' => 'SBX-'.strtoupper(bin2hex(random_bytes(5)))])->save();

        return ['kind' => 'simulate'];
    }

    /** Sandbox outcomes are decided by the buttons, never by asking a bank. */
    public function check(PaymentAttempt $attempt): ?array
    {
        return ['status' => 'pending'];
    }
}

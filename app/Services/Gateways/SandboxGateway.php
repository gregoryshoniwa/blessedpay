<?php

namespace App\Services\Gateways;

use App\Models\Payment;
use App\Services\Gateways\Contracts\BankGateway;

/**
 * A pretend bank for test-mode keys. The "bank page" is our own
 * /sandbox/{payment} screen with Approve / Decline / Cancel buttons, so the
 * whole flow (redirect, outcome, signed callback, return) can be exercised
 * without real money and before the real bank integration exists.
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

    public function start(Payment $payment): string
    {
        if ($payment->gateway_reference === null) {
            $payment->forceFill(['gateway_reference' => 'SBX-'.strtoupper(bin2hex(random_bytes(5)))])->save();
        }

        return route('sandbox.show', $payment->publicId());
    }

    /** Sandbox outcomes are decided on the sandbox page, never by polling. */
    public function refresh(Payment $payment): ?array
    {
        return null;
    }
}

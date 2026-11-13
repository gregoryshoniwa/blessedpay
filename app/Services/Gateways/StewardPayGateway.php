<?php

namespace App\Services\Gateways;

use App\Models\Payment;
use App\Services\Gateways\Contracts\BankGateway;

/**
 * StewardPay — TN CyberTech Bank's gateway. BLESSLUXE is the merchant.
 *
 * NOT WRITTEN YET. The bank's API documentation and sandbox credentials have
 * not been received (see CLAUDE.md). Until they are, live-mode payments are
 * refused with a clear message rather than guessed at. When the docs arrive:
 * fill in start() (create the transaction, return the bank's hosted-page URL,
 * store their reference) and refresh() (their status call), add their callback
 * route if they have one, and make isConfigured() check the credentials in
 * config('services.stewardpay').
 */
class StewardPayGateway implements BankGateway
{
    public function id(): string
    {
        return 'stewardpay';
    }

    public function label(): string
    {
        return 'StewardPay (TN CyberTech Bank)';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function start(Payment $payment): string
    {
        throw new GatewayUnavailable('Live payments are not switched on yet: the bank integration is still being set up.');
    }

    public function refresh(Payment $payment): ?array
    {
        return null;
    }
}

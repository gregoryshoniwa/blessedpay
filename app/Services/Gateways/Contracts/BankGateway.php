<?php

namespace App\Services\Gateways\Contracts;

use App\Models\Payment;
use App\Services\Gateways\GatewayUnavailable;

/**
 * One bank-side processor. BlessedPay never takes card details itself: a
 * gateway only knows where to SEND the shopper and how to ask what HAPPENED.
 *
 * To add a processor: implement this and register it in Gateways::all().
 */
interface BankGateway
{
    /** Stable id stored on payments: 'sandbox', 'stewardpay'. */
    public function id(): string;

    public function label(): string;

    /** Credentials present and the integration written? */
    public function isConfigured(): bool;

    /**
     * Begin the payment at the processor and return the URL to send the
     * shopper to. May set gateway_reference on the payment.
     *
     * @throws GatewayUnavailable
     */
    public function start(Payment $payment): string;

    /**
     * Ask the processor for the current state.
     *
     * @return array{status:string, gateway_reference?:string, method?:string, raw?:array}|null
     *                                                                                          null when it can't be reached or has nothing new — leave the payment as it is
     */
    public function refresh(Payment $payment): ?array;
}

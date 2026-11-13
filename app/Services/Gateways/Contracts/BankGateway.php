<?php

namespace App\Services\Gateways\Contracts;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\Gateways\GatewayUnavailable;

/**
 * One bank-side processor. BlessedPay never takes card details itself: a
 * gateway only knows which ways of paying it offers, how to START one, and
 * how to ask the bank what HAPPENED.
 *
 * To add a processor: implement this and register it in Gateways::all().
 */
interface BankGateway
{
    /** Stable id stored on payments: 'sandbox', 'stewardpay'. */
    public function id(): string;

    public function label(): string;

    /** Credentials present? */
    public function isConfigured(): bool;

    /**
     * The ways this payment can be paid, in the order to offer them.
     *
     * @return list<array{id:string, label:string, hint:string, needs:list<string>}>
     *                                                                               needs: what the checkout must collect first — [] or ['phone']
     */
    public function methods(Payment $payment): array;

    /**
     * True when begin() only returns once the shopper has answered (an EcoCash
     * prompt on their phone). The checkout then answers the browser first and
     * calls begin() after the response has been sent.
     */
    public function beginsSlowly(string $method): bool;

    /**
     * Start one try at the bank. Returns what the checkout does next:
     *   ['kind' => 'wait']                                   keep asking for the status
     *   ['kind' => 'card', 'session_id', 'script', 'url']    show the bank's card form in the page
     *   ['kind' => 'open', 'url']                            open the bank's own page in a new tab, and wait
     *   ['kind' => 'simulate']                               sandbox: show Approve / Decline buttons
     * and, when the bank already answered, 'result' => the same shape check() returns.
     *
     * @throws GatewayUnavailable with a message that is safe to show the shopper
     */
    public function begin(PaymentAttempt $attempt): array;

    /**
     * Ask the bank what happened to a try.
     *
     * @return array{status:'paid'|'failed'|'pending', gateway_reference?:?string, error?:?string, raw?:?array}|null
     *                                                                                                               null when the bank can't be reached — leave the attempt as it is
     */
    public function check(PaymentAttempt $attempt): ?array;
}

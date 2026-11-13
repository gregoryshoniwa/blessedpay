<?php

namespace App\Services\Gateways;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\Gateways\Contracts\BankGateway;
use App\Services\Gateways\StewardPay\Client;

/**
 * StewardPay / CyberPay — TN CyberTech Bank's gateway. BLESSLUXE is the
 * merchant; money lands in its corporate account at the bank.
 *
 * Three ways to pay, each a different conversation with the bank:
 *
 *  card     MPGS, WEB_EMBEDDED. The bank opens a Mastercard session; the card
 *           form is Mastercard's own, shown inside our page — we never see the
 *           card. USD only. The bank never calls us back for cards: asking it
 *           (check) is what completes the payment.
 *  ecocash  ECOCASH, REMOTE. The bank pushes a prompt to the shopper's phone
 *           and the call does not return until they answer (up to ~2 min).
 *  bank     TNCB, WEB. The shopper signs in on the bank's own page with their
 *           CyberCash details; their phone number must match the one we send.
 */
class StewardPayGateway implements BankGateway
{
    public function __construct(private ?Client $client = null)
    {
        $this->client ??= app(Client::class);
    }

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
        return $this->client->isConfigured();
    }

    public function methods(Payment $payment): array
    {
        return self::methodList($payment->currency);
    }

    /** Shared with the sandbox so test mode offers exactly what live does. */
    public static function methodList(string $currency): array
    {
        return array_values(array_filter([
            ['id' => 'ecocash', 'label' => 'EcoCash', 'hint' => 'Approve the payment on your phone', 'needs' => ['phone']],
            $currency === 'USD' ? ['id' => 'card', 'label' => 'Visa or Mastercard', 'hint' => 'Card details go straight to the bank', 'needs' => []] : null,
            ['id' => 'bank', 'label' => 'TN CyberTech Bank account', 'hint' => 'Sign in with your CyberCash details', 'needs' => ['phone']],
        ]));
    }

    public function beginsSlowly(string $method): bool
    {
        return $method === 'ecocash';
    }

    public function begin(PaymentAttempt $attempt): array
    {
        $payment = $attempt->payment;

        $fields = [
            // The bank requires a phone for every mode, even cards, where it is only recorded.
            'phone' => $attempt->phone ?: (string) config('services.stewardpay.fallback_phone', '263000000000'),
            'amount' => (string) $attempt->amount,
            'currency' => $payment->currency,
            'billerReference' => $attempt->reference,
            'responseUrl' => route('hooks.stewardpay'),
        ];

        $answer = match ($attempt->method) {
            'card' => $this->client->initialize($fields + [
                'authType' => 'WEB_EMBEDDED', 'paymentProcessorLabel' => 'MPGS',
                'returnUrl' => route('pay.card-return', $payment->publicId()),
            ]),
            'bank' => $this->client->initialize($fields + ['authType' => 'WEB', 'paymentProcessorLabel' => 'TNCB']),
            'ecocash' => $this->client->initialize($fields + ['authType' => 'REMOTE', 'paymentProcessorLabel' => 'ECOCASH'], timeout: 140),
            default => throw new GatewayUnavailable('That way of paying is not available.'),
        };

        if (filled($answer['uid'] ?? null)) {
            $attempt->forceFill(['gateway_uid' => $answer['uid']])->save();
        }

        // The bank answers HTTP 200 even when it could not start: the status says so.
        $result = $this->read($attempt, $answer);

        if ($attempt->method === 'ecocash') {
            return ['kind' => 'wait', 'result' => $result];
        }

        if ($result['status'] === 'failed') {
            throw new GatewayUnavailable($result['error'] ?: 'The bank could not start this payment. Please try another way to pay.');
        }

        if ($attempt->method === 'card') {
            return $this->cardStep($answer);
        }

        if (! is_string($answer['targetUrl'] ?? null) || ! str_starts_with($answer['targetUrl'], 'https://')) {
            throw new GatewayUnavailable('The bank did not give a page to continue on. Please try another way to pay.');
        }

        return ['kind' => 'open', 'url' => $answer['targetUrl']];
    }

    public function check(PaymentAttempt $attempt): ?array
    {
        try {
            $answer = match (true) {
                $attempt->gateway_uid === null => $this->client->actionByReference($attempt->reference),
                $attempt->method === 'card' => $this->client->cardPoll($attempt->gateway_uid),
                default => $this->client->action($attempt->gateway_uid),
            };
        } catch (GatewayUnavailable) {
            return null;
        }

        if ($answer === null) {
            // No record at the bank. Give a slow start a few minutes before giving up.
            return $attempt->created_at->lt(now()->subMinutes(4))
                ? ['status' => 'failed', 'error' => 'The bank has no record of this attempt.']
                : ['status' => 'pending'];
        }

        if ($attempt->gateway_uid === null && filled($answer['uid'] ?? null)) {
            $attempt->forceFill(['gateway_uid' => $answer['uid']])->save();
        }

        return $this->read($attempt, $answer);
    }

    /** Turn the bank's record into paid / failed / pending. */
    public function read(PaymentAttempt $attempt, array $answer): array
    {
        $transaction = is_array($answer['transaction'] ?? null) ? $answer['transaction'] : [];
        $status = strtoupper((string) ($answer['status'] ?? 'PENDING'));
        $reference = $transaction['debitReference'] ?? $answer['debitReference'] ?? $transaction['reference'] ?? $answer['reference'] ?? null;
        $error = $answer['errorMessage'] ?? $transaction['errorMessage'] ?? null;

        $result = match (true) {
            // SAF: the shopper was debited and the bank is still crediting the merchant. The money has left them.
            in_array($status, ['SUCCESS', 'SAF'], true) => 'paid',
            in_array($status, ['FAILED', 'CANCELLED', 'INVALID_REQUEST', 'EXPIRED', 'REVERSED'], true) => 'failed',
            default => 'pending', // PENDING, PROCESSING, AUTHORIZED and anything new
        };

        // Never mark paid on an amount that is not the one we asked for.
        $amount = $answer['amount'] ?? $transaction['amount'] ?? null;
        if ($result === 'paid' && is_numeric($amount) && abs((float) $amount - (float) $attempt->amount) > 0.004) {
            $result = 'failed';
            $error = "The bank reported a different amount ({$amount}) from the one requested ({$attempt->amount}).";
        }

        unset($answer['targetUrl'], $answer['sessionId'], $answer['successIndicator']); // not for the log: the page address can carry our token

        return [
            'status' => $result,
            'gateway_reference' => is_string($reference) ? $reference : null,
            'error' => $result === 'failed' ? self::friendly($status, $error) : null,
            'raw' => $answer,
        ];
    }

    private function cardStep(array $answer): array
    {
        $sessionId = $answer['sessionId'] ?? null;
        $page = $answer['targetUrl'] ?? null;

        if (! is_string($sessionId) || ! is_string($page) || ! ($host = parse_url($page, PHP_URL_HOST))) {
            throw new GatewayUnavailable('The bank could not open the card form. Please try another way to pay.');
        }

        return [
            'kind' => 'card',
            'session_id' => $sessionId,
            // Mastercard's own script draws the card form; it must come from the same host as the session.
            'script' => 'https://'.$host.'/static/checkout/checkout.min.js',
            'url' => $page,
        ];
    }

    private static function friendly(string $status, ?string $error): string
    {
        return match ($status) {
            'CANCELLED' => 'The payment was cancelled.',
            'EXPIRED' => 'The payment was not completed in time.',
            'REVERSED' => 'The payment was reversed by the bank.',
            default => filled($error) ? mb_substr((string) $error, 0, 200) : 'The payment did not go through.',
        };
    }
}

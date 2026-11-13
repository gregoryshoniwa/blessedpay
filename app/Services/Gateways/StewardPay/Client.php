<?php

namespace App\Services\Gateways\StewardPay;

use App\Services\Gateways\GatewayUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for StewardPay / CyberPay "Core API" (TN CyberTech Bank).
 *
 * The contract below was read from the bank's own gateway code, not just the
 * PDF guide, which is thin and in places wrong:
 *  - Live is {base}/merchant/... with base https://api.stewardpay.co.zw/v1.
 *    (/lab/v2 is the bank's test environment and rejects live credentials.)
 *  - Every answer is wrapped: {message, status, errors, body}.
 *  - Tokens last 300 seconds live. We cache one for a little less.
 *  - hash = sha256(clientId + encryptionKey + phone + amount), with phone and
 *    amount EXACTLY as sent ("50" and "50.00" hash differently). The key is
 *    never sent — the bank uses its own copy.
 *  - `phone` is required for every mode (the bank crashes without it).
 *  - `sandbox` in the body does nothing.
 *  - The bank echoes clientSecret back in its answer: scrub() before storing.
 */
class Client
{
    private const TOKEN_CACHE = 'stewardpay.token';

    public function isConfigured(): bool
    {
        return filled($this->config('client_id')) && filled($this->config('client_secret')) && filled($this->config('encryption_key'));
    }

    /**
     * Start a payment session ("sdk/action").
     *
     * @param  array{phone:string, amount:string, currency:string, authType:string, paymentProcessorLabel:string, billerReference:string, responseUrl:string, returnUrl?:string}  $fields
     * @return array the bank's SdkAction, scrubbed. NB: `targetUrl` can carry our bearer token — hand it to the shopper's browser, never store it.
     */
    public function initialize(array $fields, ?int $timeout = null): array
    {
        $body = $fields + [
            'clientId' => $this->config('client_id'),
            'clientSecret' => $this->config('client_secret'),
            'action' => 'PAYMENT',
            'hash' => $this->hash($fields['phone'], $fields['amount']),
        ];

        return $this->body($this->send('post', '/merchant/sdk/action', $body, $timeout));
    }

    /** The session by the bank's id. Reads the bank's record only. */
    public function action(string $uid): ?array
    {
        return $this->body($this->send('get', '/merchant/sdk/'.rawurlencode($uid)), allowEmpty: true);
    }

    /** The session by OUR reference — for when we never received the bank's id. */
    public function actionByReference(string $reference): ?array
    {
        return $this->body($this->send('get', '/merchant/sdk/reference/'.rawurlencode($reference)), allowEmpty: true);
    }

    /**
     * Card payments: asks Mastercard for the order's state and, if it was
     * paid, settles it at the bank. Cards never trigger a callback, so this
     * call is what completes a card payment. Safe to repeat.
     */
    public function cardPoll(string $uid): ?array
    {
        return $this->body($this->send('get', '/merchant/sdk/mpgs/poll/'.rawurlencode($uid)), allowEmpty: true);
    }

    public function hash(string $phone, string $amount): string
    {
        return hash('sha256', $this->config('client_id').$this->config('encryption_key').$phone.$amount);
    }

    /**
     * The bank signs its callback: hash = sha256(encryptionKey + amount + trace + reference),
     * built from the callback's own fields.
     */
    public function callbackHashMatches(array $payload, ?string $rawAmount = null): bool
    {
        $given = strtolower((string) ($payload['hash'] ?? ''));
        if ($given === '') {
            return false;
        }

        // The bank builds the string in Java; how it prints the amount is not
        // documented, so accept any honest rendering of the same number.
        $amounts = array_unique(array_filter([
            $rawAmount,
            isset($payload['amount']) ? (string) $payload['amount'] : null,
            isset($payload['numericAmount']) ? (string) $payload['numericAmount'] : null,
            isset($payload['amount']) && is_numeric($payload['amount']) ? number_format((float) $payload['amount'], 2, '.', '') : null,
            isset($payload['amount']) && is_numeric($payload['amount']) ? rtrim(rtrim(number_format((float) $payload['amount'], 2, '.', ''), '0'), '.') : null,
        ], fn ($v) => $v !== null && $v !== ''));

        foreach ($amounts as $amount) {
            $expected = hash('sha256', $this->config('encryption_key').$amount.($payload['trace'] ?? '').($payload['reference'] ?? ''));
            if (hash_equals($expected, $given)) {
                return true;
            }
        }

        return false;
    }

    /** Remove everything secret before a bank answer is stored or shown. */
    public static function scrub(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        foreach (['clientSecret', 'encryptionKey', 'hash', 'client'] as $key) {
            unset($data[$key]);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }

    private function send(string $method, string $path, array $body = [], ?int $timeout = null): Response
    {
        try {
            $response = $this->request($timeout)->{$method}($path, $body);

            if ($response->status() === 401) { // token expired between cache and use
                Cache::forget(self::TOKEN_CACHE);
                $response = $this->request($timeout)->{$method}($path, $body);
            }

            return $response;
        } catch (ConnectionException $e) {
            throw new GatewayUnavailable('The bank could not be reached. Please try again in a moment.', previous: $e);
        }
    }

    private function request(?int $timeout): PendingRequest
    {
        return Http::baseUrl($this->config('base_url'))
            ->acceptJson()->asJson()
            ->withToken($this->token())
            ->timeout($timeout ?? (int) $this->config('timeout', 25))
            ->connectTimeout(8);
    }

    private function token(): string
    {
        if ($cached = Cache::get(self::TOKEN_CACHE)) {
            return $cached;
        }

        try {
            $response = Http::baseUrl($this->config('base_url'))->acceptJson()->asJson()->timeout(15)->connectTimeout(8)
                ->post('/merchant/client/token', [
                    'clientId' => $this->config('client_id'),
                    'clientSecret' => $this->config('client_secret'),
                ]);
        } catch (ConnectionException $e) {
            throw new GatewayUnavailable('The bank could not be reached. Please try again in a moment.', previous: $e);
        }

        $token = $response->json('body.access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            report(new \RuntimeException('StewardPay token refused: HTTP '.$response->status()));
            throw new GatewayUnavailable('The bank did not accept our connection. Please try again later.');
        }

        // Keep it for a little less than the bank does, so it never expires in our hands.
        Cache::put(self::TOKEN_CACHE, $token, max(30, (int) $response->json('body.expires_in', 300) - 60));

        return $token;
    }

    private function body(Response $response, bool $allowEmpty = false): ?array
    {
        $body = $response->json('body');

        if ($response->successful() && is_array($body)) {
            return self::scrub($body);
        }

        if ($response->successful() && $allowEmpty) {
            return null; // the bank has no such record
        }

        $reason = collect((array) $response->json('errors'))->filter(fn ($v) => is_string($v))->first()
            ?? 'HTTP '.$response->status();
        report(new \RuntimeException('StewardPay refused a request: '.$reason));

        throw new GatewayUnavailable('The bank could not start this payment. Please try another way to pay.');
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config('services.stewardpay.'.$key, $default);
    }
}

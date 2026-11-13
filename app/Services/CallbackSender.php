<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tells the company's system what happened to a payment.
 *
 * The body is JSON. It is signed so the receiver can prove it came from us:
 *
 *   X-BlessedPay-Signature: t=<unix time>,v1=<hex hmac-sha256 of "<t>.<raw body>">
 *
 * keyed with the company's callback secret. X-BlessedPay-Event is the same for
 * every retry of the same news, so the receiver can ignore a repeat.
 *
 * No queue worker runs in production, so this is sent inside the request with
 * a short timeout; `php artisan payments:callbacks` retries the ones that
 * failed, and the console has a "Send again" button.
 */
class CallbackSender
{
    public const MAX_ATTEMPTS = 8;

    public function send(Payment $payment): bool
    {
        if (! $payment->callback_url || ! $payment->isFinal()) {
            return false;
        }

        $body = json_encode([
            'event' => 'payment.'.$payment->status,
            'payment' => collect($payment->toApi())->except('redirect_url')->all(),
        ], JSON_UNESCAPED_SLASHES);

        $timestamp = now()->timestamp;
        $payment->increment('callback_attempts');

        try {
            if ($token = TryHook::accepts($payment->callback_url)) {
                // The console's "Try it" page: hand it over directly instead of
                // calling ourselves over the network (which can wait on itself).
                TryHook::record($token, [
                    'Content-Type' => 'application/json',
                    'X-BlessedPay-Signature' => "t={$timestamp},v1=".self::sign($timestamp, $body, $payment->company->callback_secret),
                    'X-BlessedPay-Event' => $payment->publicId().'.'.$payment->status,
                    'User-Agent' => 'BlessedPay/1.0',
                ], $body);
                $payment->forceFill(['callback_status' => 'delivered'])->save();
                $payment->log('callback.delivered', "Callback delivered (to the Try it page), attempt {$payment->callback_attempts}", ['url' => $payment->callback_url]);

                return true;
            }

            if (! self::isPublicAddress($payment->callback_url)) {
                throw new \RuntimeException('The callback address is not a public web address.');
            }

            $response = Http::timeout(8)->connectTimeout(4)
                ->withHeaders([
                    'X-BlessedPay-Signature' => "t={$timestamp},v1=".self::sign($timestamp, $body, $payment->company->callback_secret),
                    'X-BlessedPay-Event' => $payment->publicId().'.'.$payment->status,
                    'User-Agent' => 'BlessedPay/1.0',
                ])
                ->withBody($body, 'application/json')
                ->post($payment->callback_url);

            $ok = $response->successful();
            $note = 'HTTP '.$response->status();
        } catch (Throwable $e) {
            $ok = false;
            $note = class_basename($e).': '.mb_substr($e->getMessage(), 0, 200);
        }

        $payment->forceFill(['callback_status' => $ok ? 'delivered' : 'failed'])->save();
        $payment->log(
            $ok ? 'callback.delivered' : 'callback.failed',
            ($ok ? 'Callback delivered' : 'Callback failed')." ({$note}), attempt {$payment->callback_attempts}",
            ['url' => $payment->callback_url, 'result' => $note],
        );

        return $ok;
    }

    /**
     * The callback address comes from outside, and we fetch it from inside our
     * network — so refuse anything that points back at private or local
     * machines. Skipped on a developer's machine, where the shop IS local.
     */
    public static function isPublicAddress(string $url): bool
    {
        if (app()->environment('local', 'testing')) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return $ips !== [];
    }

    public static function sign(int $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\ApiRequest;
use App\Models\Payment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Keeps every call to the company-facing API: the request as it arrived and
 * our answer in full. It is shown in the console's API log and, when the call
 * is about a payment, as a step in that payment's audit trail.
 *
 * The API key itself is never kept — only its first characters.
 */
class LogApiRequest
{
    private const MAX = 20000; // characters of a body worth keeping

    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $response = $next($request);

        try {
            $this->record($request, $response, (int) round((microtime(true) - $started) * 1000));
        } catch (Throwable $e) {
            report($e); // the log must never break the API
        }

        return $response;
    }

    private function record(Request $request, Response $response, int $ms): void
    {
        $token = (string) $request->bearerToken();
        $used = $request->attributes->get('api_key');
        $hint = match (true) {
            $token === '' => null,
            str_starts_with($token, ApiKey::PASS_PREFIX) => $used ? $used->prefix.'… (Try it)' : 'bpt_… (expired)',
            default => substr($token, 0, 12).'…',
        };
        $answer = json_decode((string) $response->getContent(), true) ?? mb_substr((string) $response->getContent(), 0, self::MAX);
        $body = strlen($request->getContent()) > self::MAX ? ['too_large' => strlen($request->getContent()).' bytes'] : ($request->json()->all() ?: null);

        $payment = Payment::findPublic((string) ($request->route('id') ?? (is_array($answer) ? ($answer['id'] ?? '') : '')));
        $company = $request->attributes->get('company');
        // A payment belongs in this log only for the company whose key asked about it.
        $payment = $payment && $company && $payment->company_id === $company->id ? $payment : null;

        $sent = [
            'headers' => array_filter([
                'Authorization' => $hint ? 'Bearer '.$hint : null,
                'Idempotency-Key' => $request->header('Idempotency-Key'),
                'Content-Type' => $request->header('Content-Type'),
                'Accept' => $request->header('Accept'),
                'User-Agent' => mb_substr((string) $request->userAgent(), 0, 200) ?: null,
            ], fn ($v) => $v !== null),
            'body' => $body,
        ];
        $line = $request->method().' /'.ltrim($request->path(), '/');
        $ok = $response->getStatusCode() < 400;

        $row = [
            'company_id' => $company?->id,
            'mode' => $request->attributes->get('mode') ?? (str_starts_with($token, 'sk_live_') ? 'live' : (str_starts_with($token, 'sk_test_') ? 'test' : null)),
            'payment_id' => $payment?->id,
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => $response->getStatusCode(),
            'key_hint' => $hint,
            'ip' => $request->ip(),
            'request' => $sent,
            'response' => is_array($answer) ? $answer : ['text' => $answer],
            'duration_ms' => $ms,
        ];

        // A shop asks "what happened?" every few seconds: the same answer again is counted, not kept again.
        if ($request->isMethod('GET') && $payment) {
            $last = ApiRequest::where('payment_id', $payment->id)->latest('id')->first();
            if ($last && $last->method === 'GET' && $last->status === $row['status'] && ($last->response['status'] ?? null) === ($row['response']['status'] ?? null)) {
                $last->forceFill(['repeats' => $last->repeats + 1, 'last_at' => now()])->save();
                $payment->logRepeat('api.status');

                return;
            }
        }

        ApiRequest::create($row);

        $payment?->log(
            $request->isMethod('GET') ? 'api.status' : 'api.request',
            ($request->isMethod('GET') ? "The company's system asked what happened" : "The company's system called the API").' → HTTP '.$response->getStatusCode()
                .($request->isMethod('GET') && is_array($answer) && isset($answer['status']) ? ', we said "'.$answer['status'].'"' : ''),
            ['request' => $line, 'sent' => $sent, 'http_status' => $response->getStatusCode(), 'received' => $answer, 'repeats' => 1],
            actor: 'merchant', level: $ok ? 'info' : 'error', ms: $ms,
        );
    }
}

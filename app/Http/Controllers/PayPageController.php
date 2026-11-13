<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Gateways\Gateways;
use App\Services\Gateways\GatewayUnavailable;
use App\Services\PaymentService;
use App\Support\EmbedOrigin;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The checkout: what a shopper sees, full-page or inside the pop-up.
 *
 * The payment's id in the address is the credential — it is long and random.
 * Nothing here uses cookies or a session, because inside a pop-up on another
 * site the browser would not send them.
 */
class PayPageController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    public function show(Request $request, string $id): Response
    {
        $payment = $this->payments->refresh($this->find($id));
        $embed = EmbedOrigin::allowed($payment, $request->query('embed'));

        if (! $payment->isFinal()) {
            $payment->log('checkout.opened', $embed ? "The shopper opened the checkout in a pop-up on {$embed}" : 'The shopper opened the checkout page', [
                'shown' => $embed ? 'pop-up' : 'full page',
                'browser' => mb_substr((string) $request->userAgent(), 0, 200),
            ], actor: 'shopper');
        }

        return response()
            ->view('pay.show', ['payment' => $payment, 'state' => $this->state($payment), 'embed' => $embed])
            // Only an allowed site may draw this page inside a frame.
            ->header('Content-Security-Policy', "frame-ancestors 'self'".($embed ? ' '.$embed : ''))
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer'); // the address is a credential: don't leak it to the bank's pages
    }

    /**
     * Where Mastercard sends the shopper when the card form is finished. It
     * usually loads INSIDE the card form's own frame (ours → the bank's → this),
     * so it carries nothing but a nudge to the checkout above it to look at the
     * payment now. Opened as a whole page, it goes back to the checkout.
     */
    public function cardReturn(string $id): Response
    {
        $payment = $this->find($id);
        $url = route('pay.show', $payment->publicId()).'?card=return';

        if (! $payment->isFinal()) {
            $payment->log('checkout.card_returned', "The bank's card form finished and sent the shopper back", actor: 'shopper', attempt: $payment->openAttempt());
        }

        $html = '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Confirming your payment</title>'
            .'<body style="margin:0;font:16px system-ui,sans-serif;background:#0e0e0e;color:#d0d0d0;display:grid;place-items:center;min-height:100vh">'
            .'<p>Confirming your payment…</p><script>'
            .'if(window.top===window.self){location.replace('.json_encode($url, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES).')}'
            .'else{var m={source:"blessedpay-card",type:"returned"};try{parent.postMessage(m,"*");parent.parent.postMessage(m,"*")}catch(e){}}'
            .'</script>';

        return response($html)->header('Cache-Control', 'no-store');
    }

    public function status(string $id): JsonResponse
    {
        return response()->json($this->state($this->payments->refresh($this->find($id))));
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $payment = $this->find($id);
        $data = $request->validate([
            'method' => ['required', 'string', 'in:card,ecocash,bank'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $phone = filled($data['phone'] ?? null) ? Phone::zimbabwe($data['phone']) : null;
        if (filled($data['phone'] ?? null) && ! $phone) {
            return response()->json(['message' => 'Enter a Zimbabwe mobile number, for example 077 123 4567.'] + $this->state($payment), 422);
        }

        try {
            $next = $this->payments->startAttempt($payment, $data['method'], $phone);
        } catch (GatewayUnavailable $e) {
            return response()->json(['message' => $e->getMessage()] + $this->state($payment->refresh()), 422);
        }

        return response()->json(['next' => $next] + $this->state($payment->refresh()));
    }

    public function cancel(string $id): JsonResponse
    {
        return response()->json($this->state($this->payments->cancel($this->find($id))));
    }

    /** Test mode only: the Approve / Decline buttons that stand in for the bank. */
    public function simulate(Request $request, string $id): JsonResponse
    {
        $payment = $this->find($id);
        abort_unless($payment->gateway === 'sandbox', 404);
        $outcome = $request->validate(['outcome' => ['required', 'in:paid,failed']])['outcome'];

        if ($attempt = $payment->openAttempt()) {
            $payment->log('sandbox.answer', 'Sandbox: "'.($outcome === 'paid' ? 'Approve' : 'Decline').'" was pressed in place of the bank', actor: 'bank', attempt: $attempt);
            $this->payments->apply($attempt, $outcome === 'paid'
                ? ['status' => 'paid', 'gateway_reference' => $attempt->gateway_uid, 'raw' => ['source' => 'sandbox']]
                : ['status' => 'failed', 'error' => 'Declined in the sandbox.', 'raw' => ['source' => 'sandbox']]);
        }

        return response()->json($this->state($payment->refresh()));
    }

    private function find(string $id): Payment
    {
        return Payment::with('company')->find(str_starts_with($id, 'pay_') ? substr($id, 4) : $id) ?? abort(404);
    }

    /** Everything the checkout page needs. Nothing secret: this is sent to the shopper's browser. */
    private function state(Payment $payment): array
    {
        $attempt = $payment->attempts()->latest('id')->first();

        return [
            'payment' => [
                'id' => $payment->publicId(),
                'status' => $payment->status,
                'mode' => $payment->mode,
                'amount' => $payment->amount,
                'fee' => $payment->fee,
                'charged' => $payment->charged(),
                'currency' => $payment->currency,
                'reference' => $payment->reference,
                'description' => $payment->description,
                'expires_at' => $payment->expires_at?->toIso8601String(),
                'paid_with' => $payment->method,
            ],
            'merchant' => ['name' => $payment->company->name],
            'methods' => $payment->isFinal() ? [] : Gateways::get($payment->gateway)->methods($payment),
            'attempt' => $attempt ? [
                'method' => $attempt->method,
                'status' => $attempt->status,
                'error' => $attempt->status === 'failed' ? $attempt->error : null,
                'phone' => Phone::masked($attempt->phone),
            ] : null,
            'phone' => Phone::masked($payment->payer_phone),
            'has_phone' => filled($payment->payer_phone),
            'return_url' => $this->payments->returnUrl($payment),
            'now' => now()->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Gateways\Gateways;
use App\Services\Gateways\GatewayUnavailable;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What the shopper sees: our page naming who they are paying and how much,
 * then the bank's page. In test mode "the bank" is the sandbox screen below.
 * Card details are never entered on a BlessedPay page.
 */
class PayPageController extends Controller
{
    public function show(string $id): View
    {
        $payment = $this->find($id);

        return view('pay.show', [
            'payment' => $payment,
            'returnUrl' => app(PaymentService::class)->returnUrl($payment),
        ]);
    }

    public function continue(string $id): RedirectResponse
    {
        $payment = $this->find($id);

        if ($payment->isFinal()) {
            return redirect()->route('pay.show', $payment->publicId());
        }

        try {
            $url = Gateways::get($payment->gateway)->start($payment);
        } catch (GatewayUnavailable $e) {
            return redirect()->route('pay.show', $payment->publicId())->with('problem', $e->getMessage());
        }

        $payment->log('shopper.redirected', 'Shopper sent to '.Gateways::get($payment->gateway)->label());

        return redirect()->away($url);
    }

    public function sandbox(string $id): View|RedirectResponse
    {
        $payment = $this->findSandbox($id);

        if ($payment->isFinal()) {
            return redirect()->route('pay.show', $payment->publicId());
        }

        return view('pay.sandbox', ['payment' => $payment]);
    }

    public function sandboxDecide(Request $request, string $id, PaymentService $payments): RedirectResponse
    {
        $payment = $this->findSandbox($id);
        $outcome = $request->validate(['outcome' => ['required', 'in:paid,failed,cancelled']])['outcome'];

        $payment = $payments->settle($payment, $outcome, [
            'method' => $outcome === 'paid' ? 'sandbox' : null,
            'raw' => ['source' => 'sandbox page', 'outcome' => $outcome],
        ]);

        return redirect()->away($payments->returnUrl($payment));
    }

    private function find(string $id): Payment
    {
        return Payment::with('company')->find(str_starts_with($id, 'pay_') ? substr($id, 4) : $id) ?? abort(404);
    }

    private function findSandbox(string $id): Payment
    {
        $payment = $this->find($id);
        abort_unless($payment->gateway === 'sandbox', 404);

        return $payment;
    }
}

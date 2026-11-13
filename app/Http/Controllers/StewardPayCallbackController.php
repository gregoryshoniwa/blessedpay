<?php

namespace App\Http\Controllers;

use App\Models\PaymentAttempt;
use App\Services\Gateways\Gateways;
use App\Services\Gateways\StewardPay\Client;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bank's server-to-server notice (our `responseUrl`). It arrives for
 * EcoCash and bank-account payments once the shopper has been debited; it
 * never arrives for cards, and it is sent once with no retry.
 *
 * So it is treated as a nudge, not as the truth: we find the try it is about,
 * note whether its signature checks out, and then ASK the bank. Only if the
 * bank cannot be reached do we act on the notice itself — and then only when
 * its signature is valid.
 */
class StewardPayCallbackController extends Controller
{
    public function __invoke(Request $request, Client $client, PaymentService $payments): JsonResponse
    {
        $payload = (array) $request->json()->all();
        $reference = $payload['billerReference'] ?? null;
        $uid = $payload['sdkActionUid'] ?? null;

        $attempt = match (true) {
            is_string($reference) && $reference !== '' => PaymentAttempt::where('reference', $reference)->first(),
            is_string($uid) && $uid !== '' => PaymentAttempt::where('gateway_uid', $uid)->first(),
            default => null,
        };

        // Answer the same way whether or not we know the reference, so the address can't be used to probe.
        if (! $attempt || $attempt->payment->gateway !== 'stewardpay') {
            return response()->json(['received' => true]);
        }

        preg_match('/"amount"\s*:\s*"?([0-9.]+)"?/', $request->getContent(), $m);
        $signed = $client->callbackHashMatches($payload, $m[1] ?? null);

        $attempt->payment->log(
            'bank.callback',
            'The bank sent a notice ('.($payload['status'] ?? 'no status').', signature '.($signed ? 'valid' : 'NOT valid').')',
            Client::forLog($payload),
            actor: 'bank',
            level: $signed ? 'info' : 'warn',
            attempt: $attempt,
        );

        $gateway = Gateways::get('stewardpay');
        $result = $gateway->check($attempt);

        if ($result === null && $signed) {
            $result = $gateway->read($attempt, $payload);
        }

        if ($result) {
            $payments->apply($attempt, $result);
        }

        return response()->json(['received' => true]);
    }
}

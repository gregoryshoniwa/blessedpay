<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\CallbackSender;
use App\Services\TryHook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The console's "Try it" page: a demo shop that shows every request and response. */
class TryController extends Controller
{
    /** Public: receives a callback the way a company's server would, and keeps it for the page. */
    public function hook(Request $request, string $token): JsonResponse
    {
        abort_unless(TryHook::validToken($token), 404);

        TryHook::record($token, [
            'Content-Type' => $request->header('Content-Type'),
            'X-BlessedPay-Signature' => $request->header('X-BlessedPay-Signature'),
            'X-BlessedPay-Event' => $request->header('X-BlessedPay-Event'),
            'User-Agent' => $request->header('User-Agent'),
        ], $request->getContent());

        return response()->json(['received' => true]);
    }

    /**
     * What arrived at a Try-it address, with the signature checked the way a
     * company's server should check it. Only callbacks about a payment this
     * user may see are shown.
     */
    public function received(Request $request, string $token): JsonResponse
    {
        abort_unless(TryHook::validToken($token), 404);

        $callbacks = collect(TryHook::read($token))->map(function (array $entry) use ($request) {
            $data = json_decode($entry['body'], true);
            $payment = is_array($data) ? Payment::findPublic((string) ($data['payment']['id'] ?? '')) : null;

            if (! $payment || ! $request->user()->reaches($payment->company_id)) {
                return null;
            }

            preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', (string) ($entry['headers']['X-BlessedPay-Signature'] ?? ''), $m);
            $valid = $m && hash_equals(CallbackSender::sign((int) $m[1], $entry['body'], $payment->company->callback_secret), $m[2]);

            return $entry + ['signature_valid' => (bool) $valid];
        })->filter()->values();

        return response()->json(['callbacks' => $callbacks]);
    }
}

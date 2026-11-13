<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ApiKey;
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
     * Use a key that already exists on the Try it page. Its secret cannot be
     * read back, so the page is given a short-lived pass that stands in for
     * it. Only for someone who may manage that company's keys — they could
     * simply create a new key otherwise — and written to the activity log.
     */
    public function pass(Request $request, int $keyId): JsonResponse
    {
        $this->need($request, 'keys.manage');
        $key = ApiKey::whereKey($keyId)->whereNull('revoked_at')->first();
        abort_unless($key && $request->user()->reaches($key->company_id), 404);
        abort_if($key->mode === 'live' && ! $key->company->isActive(), 422, 'Live keys work once the company is approved.');

        ActivityLog::record($request, 'key.tried', "Used {$key->mode} API key \"{$key->name}\" on the Try it page", $key->company_id);

        return response()->json([
            'pass' => $key->pass(),
            'minutes' => ApiKey::PASS_MINUTES,
            'key' => ['id' => $key->id, 'name' => $key->name, 'mode' => $key->mode, 'prefix' => $key->prefix, 'company' => $key->company->name],
        ]);
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

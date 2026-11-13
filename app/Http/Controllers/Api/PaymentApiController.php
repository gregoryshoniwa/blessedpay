<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Gateways\Gateways;
use App\Services\IdempotencyConflict;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The API a company's system calls (the shop's gateway driver):
 *   POST /api/v1/payments        start a payment → id + redirect_url
 *   GET  /api/v1/payments/{id}   what happened to it
 */
class PaymentApiController extends Controller
{
    public function store(Request $request, PaymentService $payments): JsonResponse
    {
        $company = $request->attributes->get('company');
        $mode = $request->attributes->get('mode');

        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/', function (string $attribute, mixed $value, \Closure $fail) {
                if ((float) $value <= 0) {
                    $fail('The amount must be more than zero.');
                }
            }],
            'currency' => ['required', 'string', 'in:USD,ZWG,usd,zwg'],
            'reference' => ['required', 'string', 'max:100'],
            'return_url' => ['required', 'url:http,https', 'max:2048'],
            'callback_url' => ['nullable', 'url:http,https', 'max:2048'],
            'description' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array', 'max:20'],
            'customer' => ['nullable', 'array'],
            'customer.phone' => ['nullable', 'string', 'max:30'],
        ]);

        $key = $request->header('Idempotency-Key');
        if ($key !== null && ($key === '' || strlen($key) > 255)) {
            return $this->error(422, 'invalid_idempotency_key', 'Idempotency-Key must be 1 to 255 characters.');
        }

        if ($mode === 'live' && ! $company->isActive()) {
            return $this->error(403, 'company_not_approved', 'This company is waiting for approval. Use a test key until it is approved.');
        }

        if (! Gateways::forMode($mode)->isConfigured()) {
            return $this->error(503, 'live_not_available', 'Live payments are not set up on this server yet. Use a test key for now.');
        }

        try {
            [$payment, $created] = $payments->create($company, $mode, $data, $key);
        } catch (IdempotencyConflict) {
            return $this->error(409, 'idempotency_conflict', 'This Idempotency-Key was already used for a different payment.');
        }

        if (! $created) {
            $payment->log('api.repeated', "The company's system sent the same request again (same Idempotency-Key); it was given this same payment", actor: 'merchant');
        }

        return response()->json($payment->toApi(), $created ? 201 : 200);
    }

    public function show(Request $request, string $id, PaymentService $payments): JsonResponse
    {
        $payment = Payment::findPublic($id);

        if (! $payment
            || $payment->company_id !== $request->attributes->get('company')->id
            || $payment->mode !== $request->attributes->get('mode')) {
            return $this->error(404, 'not_found', 'No such payment.');
        }

        return response()->json($payments->refresh($payment)->toApi());
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}

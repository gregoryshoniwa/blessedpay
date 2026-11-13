<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ApiRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The API log: every call a company's system made to /api/v1, with the
 * request and our answer in full. A company sees its own; staff see every
 * company's, and also the calls made with a key that was not recognised.
 */
class ApiLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->need($request, 'payments.view');
        $request->validate([
            'mode' => ['nullable', 'in:test,live'],
            'result' => ['nullable', 'in:ok,failed'],
            'method' => ['nullable', 'in:GET,POST'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $companyId = $this->companyFilter($request);
        $mode = $request->query('mode') === 'live' ? 'live' : 'test';

        $page = ApiRequest::query()->with('company:id,name')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            // A call with an unrecognised key has no mode: staff see it under both.
            ->where(fn ($q) => $q->where('mode', $mode)->when($user->isStaff() && $companyId === null, fn ($w) => $w->orWhereNull('mode')))
            ->when($request->query('result') === 'ok', fn ($q) => $q->where('status', '<', 400))
            ->when($request->query('result') === 'failed', fn ($q) => $q->where('status', '>=', 400))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->query('method')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim($request->query('q'));
                $q->where(fn ($w) => $w->where('payment_id', str_starts_with($term, 'pay_') ? substr($term, 4) : $term)
                    ->orWhere('request->body->reference', $term)
                    ->orWhere('request->headers->Idempotency-Key', $term)
                    ->orWhere('ip', $term));
            })
            ->latest('id')
            ->paginate(25);

        return response()->json([
            'requests' => collect($page->items())->map(fn (ApiRequest $r) => [
                'id' => $r->id,
                'company' => $r->company ? ['id' => $r->company->id, 'name' => $r->company->name] : null,
                'mode' => $r->mode,
                'payment_id' => $r->payment_id ? 'pay_'.$r->payment_id : null,
                'method' => $r->method,
                'path' => $r->path,
                'status' => $r->status,
                'key_hint' => $r->key_hint,
                'ip' => $r->ip,
                'request' => $r->request,
                'response' => $r->response,
                'ms' => $r->duration_ms,
                'repeats' => $r->repeats,
                'last_at' => $r->last_at?->toIso8601String(),
                'at' => $r->created_at?->toIso8601String(),
            ]),
            'page' => $page->currentPage(),
            'pages' => $page->lastPage(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
            'total' => $page->total(),
        ]);
    }
}

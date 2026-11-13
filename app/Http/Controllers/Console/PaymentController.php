<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Payment;
use App\Services\CallbackSender;
use App\Services\Fees;
use App\Services\PaymentService;
use App\Support\Diagnosis;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $this->need($request, 'payments.view');
        $mode = $request->query('mode') === 'test' ? 'test' : 'live';
        $base = fn () => $this->scoped($request)->where('mode', $mode);

        $paidToday = $base()->where('status', 'paid')->where('paid_at', '>=', now()->startOfDay())
            ->selectRaw('currency, count(*) as n, sum(amount) as total')->groupBy('currency')->get();
        $paidMonth = $base()->where('status', 'paid')->where('paid_at', '>=', now()->startOfMonth())
            ->selectRaw('currency, count(*) as n, sum(amount) as total')->groupBy('currency')->get();

        return response()->json([
            'mode' => $mode,
            'paid_today' => $paidToday->map(fn ($r) => ['currency' => $r->currency, 'count' => (int) $r->n, 'total' => number_format((float) $r->total, 2, '.', '')]),
            'paid_month' => $paidMonth->map(fn ($r) => ['currency' => $r->currency, 'count' => (int) $r->n, 'total' => number_format((float) $r->total, 2, '.', '')]),
            'pending' => $base()->where('status', 'pending')->count(),
            'failed_week' => $base()->whereIn('status', ['failed', 'cancelled'])->where('created_at', '>=', now()->subDays(7))->count(),
            'callbacks_failed' => $base()->where('callback_status', 'failed')->count(),
            'recent' => $base()->with('company:id,name')->latest('created_at')->limit(8)->get()->map(fn (Payment $p) => self::row($p)),
            'companies' => $request->user()->isStaff() && $this->companyFilter($request) === null ? $this->perCompany($mode) : null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->need($request, 'payments.view');

        $perPage = in_array((int) $request->query('per_page'), [10, 25, 50, 100], true) ? (int) $request->query('per_page') : 25;
        $query = $this->filtered($request)->with('company:id,name')->withCount('attempts');

        match ($request->query('sort')) {
            'oldest' => $query->oldest('created_at')->oldest('id'),
            'amount_high' => $query->orderByDesc('amount')->latest('id'),
            'amount_low' => $query->orderBy('amount')->latest('id'),
            default => $query->latest('created_at')->latest('id'),
        };

        $page = $query->paginate($perPage);

        // What the whole filtered set adds up to, not just this page.
        $totals = $this->filtered($request)->selectRaw('status, currency, count(*) as n, sum(amount) as total')
            ->groupBy('status', 'currency')->get();

        return response()->json([
            'payments' => collect($page->items())->map(fn (Payment $p) => self::row($p) + ['tries' => (int) $p->attempts_count]),
            'page' => $page->currentPage(),
            'pages' => $page->lastPage(),
            'per_page' => $perPage,
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
            'total' => $page->total(),
            'summary' => [
                'by_status' => $totals->groupBy('status')->map(fn ($rows) => (int) $rows->sum('n')),
                'paid' => $totals->where('status', 'paid')->values()->map(fn ($r) => [
                    'currency' => $r->currency, 'count' => (int) $r->n, 'total' => number_format((float) $r->total, 2, '.', ''),
                ]),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->need($request, 'payments.export');
        $query = $this->filtered($request)->with('company:id,name')->latest('created_at');
        ActivityLog::record($request, 'payments.exported', 'Exported payments to a file', $this->companyFilter($request));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Payment', 'Company', 'Mode', 'Reference', 'Amount', 'Fee on top', 'Currency', 'Status', 'Paid with', 'Bank reference', 'Callback', 'Created', 'Paid', 'Finished']);
            $query->chunk(500, function ($payments) use ($out) {
                foreach ($payments as $p) {
                    fputcsv($out, [
                        $p->publicId(), $p->company->name, $p->mode, self::csvSafe($p->reference), $p->amount, $p->fee, $p->currency,
                        $p->status, $p->method, $p->gateway_reference, $p->callback_status,
                        $p->created_at?->toIso8601String(), $p->paid_at?->toIso8601String(), $p->finished_at?->toIso8601String(),
                    ]);
                }
            });
            fclose($out);
        }, 'blessedpay-payments-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->need($request, 'payments.view');
        $payment = $this->find($request, $id);

        return response()->json(['payment' => self::row($payment) + [
            'description' => $payment->description,
            'return_url' => $payment->return_url,
            'callback_url' => $payment->callback_url,
            'idempotency_key' => $payment->idempotency_key,
            'metadata' => $payment->metadata,
            'callback_attempts' => $payment->callback_attempts,
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'finished_at' => $payment->finished_at?->toIso8601String(),
            'diagnosis' => Diagnosis::of($payment),
            'attempts' => $payment->attempts->map(fn ($a) => [
                'id' => $a->id, 'method' => $a->label(), 'status' => $a->status, 'reference' => $a->reference,
                'bank_id' => $a->gateway_uid, 'phone' => $a->phone, 'amount' => $a->amount, 'error' => $a->error,
                'at' => $a->created_at?->toIso8601String(),
            ]),
            'events' => $payment->events->map(fn ($e) => [
                'id' => $e->id, 'type' => $e->type, 'actor' => $e->actor, 'level' => $e->level,
                'attempt_id' => $e->attempt_id, 'ms' => $e->duration_ms, 'summary' => $e->summary,
                'data' => $e->data ? collect($e->data)->except('signature')->all() : null,
                'at' => $e->created_at?->toIso8601String(),
            ]),
        ]]);
    }

    /** Ask the bank again about a payment that is still pending. */
    public function refresh(Request $request, string $id, PaymentService $payments): JsonResponse
    {
        $user = $this->need($request, 'payments.view');
        $payment = $this->find($request, $id);
        $payment->log('staff.recheck', "{$user->name} asked the bank again from the console", actor: 'staff');
        $payments->refresh($payment, 0);

        return $this->show($request, $id);
    }

    public function resendCallback(Request $request, string $id, CallbackSender $callbacks): JsonResponse
    {
        $this->need($request, 'callbacks.resend');
        $payment = $this->find($request, $id);
        abort_unless($payment->isFinal() && $payment->callback_url, 422, 'There is no callback to send for this payment.');

        $payment->log('staff.callback_resent', "{$request->user()->name} sent the callback again from the console", actor: 'staff');
        $ok = $callbacks->send($payment);
        ActivityLog::record($request, 'callback.resent', "Sent the callback again for {$payment->reference}", $payment->company_id);

        return response()->json(['delivered' => $ok] + $this->show($request, $id)->getData(true));
    }

    /** Staff only: the same figures as the tiles, one line per company. */
    private function perCompany(string $mode): array
    {
        $base = fn () => Payment::query()->where('mode', $mode);
        $paid = $base()->where('status', 'paid')->where('paid_at', '>=', now()->startOfMonth())
            ->selectRaw('company_id, currency, count(*) as n, sum(amount) as total')->groupBy('company_id', 'currency')->get()->groupBy('company_id');
        $today = $base()->where('status', 'paid')->where('paid_at', '>=', now()->startOfDay())
            ->selectRaw('company_id, currency, count(*) as n, sum(amount) as total')->groupBy('company_id', 'currency')->get()->groupBy('company_id');
        $count = fn ($query) => $query->selectRaw('company_id, count(*) as n')->groupBy('company_id')->pluck('n', 'company_id');
        $all = $count($base());
        $pending = $count($base()->where('status', 'pending'));
        $failed = $count($base()->whereIn('status', ['failed', 'cancelled'])->where('created_at', '>=', now()->subDays(7)));
        $callbacks = $count($base()->where('callback_status', 'failed'));
        $money = fn ($rows) => collect($rows)->map(fn ($r) => ['currency' => $r->currency, 'count' => (int) $r->n, 'total' => number_format((float) $r->total, 2, '.', '')])->values();

        return Company::orderBy('name')->get(['id', 'name', 'status'])->map(fn (Company $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status,
            'paid_today' => $money($today[$c->id] ?? []),
            'paid_month' => $money($paid[$c->id] ?? []),
            'transactions' => (int) ($all[$c->id] ?? 0),
            'pending' => (int) ($pending[$c->id] ?? 0),
            'failed_week' => (int) ($failed[$c->id] ?? 0),
            'callbacks_failed' => (int) ($callbacks[$c->id] ?? 0),
        ])->all();
    }

    private function find(Request $request, string $id): Payment
    {
        $payment = Payment::with(['company:id,name', 'events', 'attempts'])->find(str_starts_with($id, 'pay_') ? substr($id, 4) : $id);
        abort_unless($payment && $request->user()->reaches($payment->company_id), 404);

        return $payment;
    }

    private function scoped(Request $request): Builder
    {
        $companyId = $this->companyFilter($request);

        return Payment::query()->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId));
    }

    private function filtered(Request $request): Builder
    {
        $request->validate([
            'status' => ['nullable', 'in:pending,paid,failed,cancelled'],
            'mode' => ['nullable', 'in:test,live'],
            'method' => ['nullable', 'in:card,ecocash,bank'],
            'currency' => ['nullable', 'in:USD,ZWG'],
            'callback' => ['nullable', 'in:delivered,failed,none'],
            'min' => ['nullable', 'numeric', 'min:0'],
            'max' => ['nullable', 'numeric', 'min:0'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->scoped($request)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('mode'), fn ($q) => $q->where('mode', $request->query('mode')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->when($request->filled('currency'), fn ($q) => $q->where('currency', $request->query('currency')))
            ->when($request->filled('min'), fn ($q) => $q->where('amount', '>=', $request->query('min')))
            ->when($request->filled('max'), fn ($q) => $q->where('amount', '<=', $request->query('max')))
            // "Paid with" is only known once paid, so a way of paying also matches payments where it was TRIED.
            ->when($request->filled('method'), fn ($q) => $q->where(fn ($w) => $w->where('method', $request->query('method'))
                ->orWhereHas('attempts', fn ($a) => $a->where('method', $request->query('method')))))
            ->when($request->filled('callback'), fn ($q) => $request->query('callback') === 'none'
                ? $q->whereNull('callback_status')
                : $q->where('callback_status', $request->query('callback')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim($request->query('q'));
                $id = str_starts_with($term, 'pay_') ? substr($term, 4) : $term;
                $phone = Phone::zimbabwe($term);
                $q->where(fn ($w) => $w->where('reference', 'like', $term.'%')
                    ->orWhere('id', $id)
                    ->orWhere('gateway_reference', $term)
                    // what the bank knows it by: our reference for a try, or the bank's own id
                    ->orWhereHas('attempts', fn ($a) => $a->where(fn ($t) => $t->where('reference', $term)->orWhere('gateway_uid', $term)
                        ->when($phone, fn ($p) => $p->orWhere('phone', $phone)))));
            });
    }

    private static function row(Payment $p): array
    {
        return [
            'id' => $p->publicId(),
            'company' => $p->company ? ['id' => $p->company->id, 'name' => $p->company->name] : null,
            'mode' => $p->mode,
            'reference' => $p->reference,
            'amount' => $p->amount,
            'fee' => $p->fee,
            'bank_fee' => $p->bank_fee,
            'margin' => $p->margin,
            'bank_credits' => $p->bank_fee !== null ? Fees::money((float) $p->charged() - (float) $p->bank_fee) : null,
            'company_keeps' => $p->bank_fee !== null ? Fees::money((float) $p->charged() - (float) $p->bank_fee - (float) $p->margin) : null,
            'charged' => $p->charged(),
            'currency' => $p->currency,
            'status' => $p->status,
            'method' => $p->method,
            'gateway' => $p->gateway,
            'gateway_reference' => $p->gateway_reference,
            'callback_status' => $p->callback_status,
            'created_at' => $p->created_at?->toIso8601String(),
            'paid_at' => $p->paid_at?->toIso8601String(),
            'finished_at' => $p->finished_at?->toIso8601String(),
        ];
    }

    /** A reference comes from outside; stop a spreadsheet reading it as a formula. */
    private static function csvSafe(?string $value): ?string
    {
        return $value !== null && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}

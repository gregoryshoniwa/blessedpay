<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Payment;
use App\Services\CallbackSender;
use App\Services\PaymentService;
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
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->need($request, 'payments.view');

        $page = $this->filtered($request)->with('company:id,name')->latest('created_at')->paginate(25);

        return response()->json([
            'payments' => collect($page->items())->map(fn (Payment $p) => self::row($p)),
            'page' => $page->currentPage(),
            'pages' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->need($request, 'payments.export');
        $query = $this->filtered($request)->with('company:id,name')->latest('created_at');
        ActivityLog::record($request, 'payments.exported', 'Exported payments to a file', $this->companyFilter($request));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Payment', 'Company', 'Mode', 'Reference', 'Amount', 'Currency', 'Status', 'Method', 'Bank reference', 'Created', 'Paid']);
            $query->chunk(500, function ($payments) use ($out) {
                foreach ($payments as $p) {
                    fputcsv($out, [
                        $p->publicId(), $p->company->name, $p->mode, self::csvSafe($p->reference), $p->amount, $p->currency,
                        $p->status, $p->method, $p->gateway_reference, $p->created_at?->toIso8601String(), $p->paid_at?->toIso8601String(),
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
            'attempts' => $payment->attempts->map(fn ($a) => [
                'id' => $a->id, 'method' => $a->label(), 'status' => $a->status, 'reference' => $a->reference,
                'bank_id' => $a->gateway_uid, 'phone' => $a->phone, 'amount' => $a->amount, 'error' => $a->error,
                'at' => $a->created_at?->toIso8601String(),
            ]),
            'events' => $payment->events->map(fn ($e) => [
                'id' => $e->id, 'type' => $e->type, 'summary' => $e->summary, 'data' => $e->data,
                'at' => $e->created_at?->toIso8601String(),
            ]),
        ]]);
    }

    /** Ask the bank again about a payment that is still pending. */
    public function refresh(Request $request, string $id, PaymentService $payments): JsonResponse
    {
        $this->need($request, 'payments.view');
        $payments->refresh($this->find($request, $id));

        return $this->show($request, $id);
    }

    public function resendCallback(Request $request, string $id, CallbackSender $callbacks): JsonResponse
    {
        $this->need($request, 'callbacks.resend');
        $payment = $this->find($request, $id);
        abort_unless($payment->isFinal() && $payment->callback_url, 422, 'There is no callback to send for this payment.');

        $ok = $callbacks->send($payment);
        ActivityLog::record($request, 'callback.resent', "Sent the callback again for {$payment->reference}", $payment->company_id);

        return response()->json(['delivered' => $ok] + $this->show($request, $id)->getData(true));
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
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->scoped($request)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('mode'), fn ($q) => $q->where('mode', $request->query('mode')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim($request->query('q'));
                $id = str_starts_with($term, 'pay_') ? substr($term, 4) : $term;
                $q->where(fn ($w) => $w->where('reference', 'like', $term.'%')
                    ->orWhere('id', $id)
                    ->orWhere('gateway_reference', $term));
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
            'charged' => $p->charged(),
            'currency' => $p->currency,
            'status' => $p->status,
            'method' => $p->method,
            'gateway' => $p->gateway,
            'gateway_reference' => $p->gateway_reference,
            'callback_status' => $p->callback_status,
            'created_at' => $p->created_at?->toIso8601String(),
            'paid_at' => $p->paid_at?->toIso8601String(),
        ];
    }

    /** A reference comes from outside; stop a spreadsheet reading it as a formula. */
    private static function csvSafe(?string $value): ?string
    {
        return $value !== null && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}

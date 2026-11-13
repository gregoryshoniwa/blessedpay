<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Fees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Charges: the bank's fee per way of paying, the gateway's own margin, how
 * both grow with the amount, and where the money from paid payments went.
 * Staff with `charges.manage` set the figures; a company sees the same rules
 * and its own totals.
 */
class ChargesController extends Controller
{
    private const EXAMPLES = ['1', '5', '10', '20', '50', '100', '250', '500', '1000'];

    public function show(Request $request): JsonResponse
    {
        $user = $this->need($request, 'charges.view');
        $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:999999999'],
            'currency' => ['nullable', 'in:USD,ZWG'],
            'method' => ['nullable', 'in:card,ecocash,bank'],
            'bearer' => ['nullable', 'in:customer,merchant'],
        ]);
        $mode = $request->query('mode') === 'live' ? 'live' : 'test';
        $currency = $request->query('currency', 'USD');
        $method = $request->query('method', 'card');
        $bearer = $request->query('bearer', 'customer');
        $companyId = $this->companyFilter($request);

        $sums = fn ($since) => Payment::query()->where('mode', $mode)->where('status', 'paid')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->when($since, fn ($q) => $q->where('paid_at', '>=', $since))
            ->selectRaw('company_id, currency, count(*) as n, sum(amount) as amount, sum(fee) as fee, sum(coalesce(bank_fee, 0)) as bank_fee, sum(coalesce(margin, 0)) as margin')
            ->groupBy('company_id', 'currency')->get();
        $shape = fn ($rows) => $rows->map(fn ($r) => [
            'company_id' => $r->company_id,
            'currency' => $r->currency,
            'payments' => (int) $r->n,
            'orders' => Fees::money((float) $r->amount),
            'shoppers_paid' => Fees::money($r->amount + $r->fee),
            'bank_kept' => Fees::money((float) $r->bank_fee),
            'bank_credited' => Fees::money($r->amount + $r->fee - $r->bank_fee),   // compare with the bank statement
            'gateway_profit' => Fees::money((float) $r->margin),
            'companies_kept' => Fees::money($r->amount + $r->fee - $r->bank_fee - $r->margin),
        ])->values();

        return response()->json([
            'mode' => $mode,
            'can_edit' => $user->may('charges.manage'),
            'is_staff' => $user->isStaff(),
            'rules' => collect(Fees::CURRENCIES)->mapWithKeys(fn ($c) => [$c => Fees::rules($c)]),
            'examples' => collect(self::EXAMPLES)->map(fn ($a) => Fees::quote($a, $currency, $method, $bearer)),
            'quote' => $request->filled('amount') ? Fees::quote($request->query('amount'), $currency, $method, $bearer) : null,
            'month' => $shape($sums(now()->startOfMonth())),
            'all_time' => $shape($sums(null)),
            'companies' => Company::query()->when($companyId !== null, fn ($q) => $q->whereKey($companyId))
                ->orderBy('name')->get(['id', 'name', 'fee_bearer'])
                ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name, 'fee_bearer' => $c->fee_bearer ?? 'merchant']),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->need($request, 'charges.manage');
        $data = $request->validate([
            'currency' => ['required', 'in:USD,ZWG'],
            'bank' => ['required', 'array:card,ecocash,bank'],
            'bank.*.percent' => ['required', 'numeric', 'min:0', 'max:15'],
            'bank.*.minimum' => ['required', 'numeric', 'min:0', 'max:1000'],
            'margin.percent' => ['required', 'numeric', 'min:0', 'max:15'],
            'margin.fixed' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);

        $before = Fees::rules($data['currency']);
        $after = [
            'bank' => collect(Fees::METHODS)->mapWithKeys(fn ($m) => [$m => [
                'percent' => round((float) ($data['bank'][$m]['percent'] ?? $before['bank'][$m]['percent']), 3),
                'minimum' => round((float) ($data['bank'][$m]['minimum'] ?? $before['bank'][$m]['minimum']), 2),
            ]])->all(),
            'margin' => ['percent' => round((float) $data['margin']['percent'], 3), 'fixed' => round((float) $data['margin']['fixed'], 2)],
        ];
        Setting::write('charges', [$data['currency'] => $after] + Setting::read('charges', []));

        $say = fn (array $r) => collect($r['bank'])->map(fn ($b, $m) => "{$m} {$b['percent']}% min ".Fees::money($b['minimum']))->implode(', ')
            ."; margin {$r['margin']['percent']}% + ".Fees::money($r['margin']['fixed']);
        ActivityLog::record($request, 'charges.changed', mb_substr("Changed the {$data['currency']} charges to: ".$say($after).'. Before: '.$say($before), 0, 250));

        return $this->show($request);
    }
}

<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ApiKey;
use App\Models\Company;
use App\Services\Fees;
use App\Services\Gateways\Gateways;
use App\Support\EmbedOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $companies = Company::query()
            ->when(! $user->isStaff(), fn ($q) => $q->whereKey($user->company_id))
            ->withCount(['users', 'payments'])
            ->orderByRaw("status = 'pending' desc") // waiting for a decision first
            ->orderBy('name')
            ->get()
            ->map(fn (Company $c) => self::shape($c));

        return response()->json(['companies' => $companies]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->need($request, 'companies.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('companies', 'name')],
            'website' => ['nullable', 'url:http,https', 'max:255'],
        ]);

        $company = Company::create($data + ['slug' => Company::uniqueSlug($data['name']), 'status' => 'active', 'callback_secret' => Company::newSecret()]);
        ActivityLog::record($request, 'company.created', "Created company {$company->name}", $company->id);

        return response()->json(['company' => self::shape($company->loadCount(['users', 'payments']))], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request, $id)->loadCount(['users', 'payments']);

        return response()->json(['company' => self::shape($company)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->need($request, 'company.edit');
        $company = $this->company($request, $id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('companies', 'name')->ignore($company->id)],
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'status' => ['sometimes', 'in:active,suspended'],
            'fee_bearer' => ['sometimes', 'in:merchant,customer'],
            'embed_origins' => ['sometimes', 'array', 'max:10'],
            'embed_origins.*' => ['string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) {
                if (EmbedOrigin::clean($value) === null) {
                    $fail('Each site must look like https://shop.example.com, with nothing after the name.');
                }
            }],
        ]);

        if (isset($data['embed_origins'])) {
            $data['embed_origins'] = array_values(array_unique(array_map(EmbedOrigin::clean(...), $data['embed_origins'])));
        }
        if (isset($data['fee_bearer']) && $data['fee_bearer'] !== $company->fee_bearer) {
            ActivityLog::record($request, 'company.fee_bearer', $data['fee_bearer'] === 'customer' ? 'Set the bank fee to be paid by shoppers' : 'Set the bank fee to be absorbed by the company', $company->id);
        }

        // Suspending or restoring a company is a staff decision, never the company's own.
        if (isset($data['status']) && $data['status'] !== $company->status) {
            abort_unless($user->may('companies.manage'), 403, 'Only BlessedPay staff can suspend or restore a company.');
            $verb = $data['status'] === 'suspended' ? 'Suspended' : ($company->status === 'pending' ? 'Approved' : 'Restored');
            ActivityLog::record($request, 'company.'.strtolower($verb), "{$verb} company {$company->name}", $company->id);
        }

        $company->update($data);

        return response()->json(['company' => self::shape($company->loadCount(['users', 'payments']))]);
    }

    /** The secret that signs callbacks — shown on request, and the look is logged. */
    public function secret(Request $request, int $id): JsonResponse
    {
        $this->need($request, 'keys.manage');
        $company = $this->company($request, $id);
        ActivityLog::record($request, 'secret.viewed', 'Viewed the callback signing secret', $company->id);

        return response()->json(['secret' => $company->callback_secret]);
    }

    public function rotateSecret(Request $request, int $id): JsonResponse
    {
        $this->need($request, 'keys.manage');
        $company = $this->company($request, $id);
        $company->update(['callback_secret' => Company::newSecret()]);
        ActivityLog::record($request, 'secret.rotated', 'Replaced the callback signing secret', $company->id);

        return response()->json(['secret' => $company->callback_secret]);
    }

    public function keys(Request $request, int $id): JsonResponse
    {
        $this->need($request, 'keys.view');
        $company = $this->company($request, $id);

        return response()->json(['keys' => $company->apiKeys()->latest('id')->get()->map(fn (ApiKey $k) => self::keyShape($k))]);
    }

    public function createKey(Request $request, int $id): JsonResponse
    {
        $user = $this->need($request, 'keys.manage');
        $company = $this->company($request, $id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'mode' => ['required', 'in:test,live'],
        ]);

        abort_if($data['mode'] === 'live' && ! $company->isActive(), 422, 'Live keys can be created once the company is approved.');

        [$key, $plain] = ApiKey::issue($company, $data['name'], $data['mode'], $user->id);
        ActivityLog::record($request, 'key.created', "Created {$data['mode']} API key \"{$data['name']}\"", $company->id);

        // The only time the full key is ever sent.
        return response()->json(['key' => self::keyShape($key), 'plain' => $plain], 201);
    }

    public function revokeKey(Request $request, int $id, int $keyId): JsonResponse
    {
        $this->need($request, 'keys.manage');
        $company = $this->company($request, $id);
        $key = $company->apiKeys()->whereKey($keyId)->firstOrFail();

        if ($key->revoked_at === null) {
            $key->update(['revoked_at' => now()]);
            ActivityLog::record($request, 'key.revoked', "Revoked API key \"{$key->name}\"", $company->id);
        }

        return response()->json(['key' => self::keyShape($key)]);
    }

    private static function shape(Company $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'website' => $c->website,
            'status' => $c->status,
            'fee_bearer' => $c->fee_bearer ?? 'merchant',
            'embed_origins' => $c->embed_origins ?? [],
            'fee' => ['percent' => Fees::rules()['bank']['card']['percent'], 'minimum' => Fees::rules()['bank']['card']['minimum']],
            'live_ready' => Gateways::forMode('live')->isConfigured(),
            'users_count' => $c->users_count ?? 0,
            'payments_count' => $c->payments_count ?? 0,
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private static function keyShape(ApiKey $k): array
    {
        return [
            'id' => $k->id,
            'name' => $k->name,
            'mode' => $k->mode,
            'prefix' => $k->prefix,
            'last_used_at' => $k->last_used_at?->toIso8601String(),
            'revoked_at' => $k->revoked_at?->toIso8601String(),
            'created_at' => $k->created_at?->toIso8601String(),
        ];
    }
}

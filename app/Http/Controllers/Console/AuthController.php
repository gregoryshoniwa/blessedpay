<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Five wrong tries per email+address, then a one-minute wait.
        $throttle = 'login:'.strtolower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            return response()->json(['message' => 'Too many tries. Wait a minute and try again.'], 429);
        }

        if (! Auth::guard('web')->attempt(['email' => $data['email'], 'password' => $data['password']])) {
            RateLimiter::hit($throttle, 60);

            return response()->json(['message' => 'That email and password do not match.'], 422);
        }

        $user = Auth::guard('web')->user();

        if ($user->status !== 'active' || $user->company?->isSuspended()) {
            Auth::guard('web')->logout();

            return response()->json(['message' => 'This account has been suspended.'], 403);
        }

        RateLimiter::clear($throttle);
        $request->session()->regenerate();
        ActivityLog::record($request, 'auth.login', 'Signed in', $user->company_id, $user);

        return response()->json(self::session($user));
    }

    /**
     * Self sign-up: a person creates their own company and becomes its owner.
     * The company starts as "pending" — they can sign in and use TEST keys at
     * once, but live keys wait until BlessedPay staff approve the company.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:120', Rule::unique('companies', 'name')],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(12)],
        ], [
            'company_name.unique' => 'A company with this name already exists. If it is yours, ask its owner to invite you.',
            'email.unique' => 'This email already has an account. Sign in instead.',
        ]);

        $user = DB::transaction(function () use ($data) {
            $company = Company::create([
                'name' => $data['company_name'],
                'slug' => Company::uniqueSlug($data['company_name']),
                'website' => $data['website'] ?? null,
                'status' => 'pending',
                'callback_secret' => Company::newSecret(),
            ]);

            return User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'company_id' => $company->id,
                'role' => 'owner',
                'status' => 'active',
            ]);
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        ActivityLog::record($request, 'company.registered', "Signed up and created company {$user->company->name}", $user->company_id, $user);

        return response()->json(self::session($user), 201);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(self::session($request->user()));
    }

    public static function session(User $user): array
    {
        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'role_label' => Roles::label($user->role),
                'is_staff' => $user->isStaff(),
                'company' => $user->company ? ['id' => $user->company->id, 'name' => $user->company->name, 'status' => $user->company->status] : null,
            ],
            'abilities' => Roles::abilitiesFor($user->role),
            'roles' => ['staff' => Roles::STAFF, 'company' => Roles::COMPANY],
        ];
    }
}

<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * People. One controller for both lists because they are the same thing at two
 * scopes: scope null = BlessedPay staff, scope N = the members of company N.
 * Nobody signs themselves up — every account starts as an invitation.
 */
class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->need($request, 'team.view');
        $scope = $this->scope($request, $request->query('company_id'));

        $users = User::where('company_id', $scope)->orderBy('name')->get();
        $invites = Invitation::with('inviter:id,name')->where('company_id', $scope)
            ->whereNull('accepted_at')->whereNull('revoked_at')->latest('id')->get();

        return response()->json([
            'company' => $scope ? Company::find($scope)?->only(['id', 'name']) : null,
            'roles' => $scope === null ? Roles::STAFF : Roles::COMPANY,
            'can_manage' => $this->canManage($request->user(), $scope),
            'can_name_owner' => $scope !== null && $request->user()->isStaff(),
            'users' => $users->map(fn (User $u) => self::shape($u, $request->user())),
            'invitations' => $invites->map(fn (Invitation $i) => self::inviteShape($i)),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $target = $this->target($request, $id);

        $data = $request->validate([
            'role' => ['sometimes', Rule::in(Roles::validFor($target->company_id))],
            'status' => ['sometimes', 'in:active,suspended'],
        ]);

        if (isset($data['role']) && $data['role'] !== $target->role) {
            abort_if($data['role'] === 'owner', 422, 'Ownership is transferred by BlessedPay staff, not by changing a role.');
            $this->keepOneSuperAdmin($target);
            ActivityLog::record($request, 'user.role_changed', "Changed {$target->name} from ".Roles::label($target->role).' to '.Roles::label($data['role']), $target->company_id);
        }

        if (isset($data['status']) && $data['status'] !== $target->status) {
            if ($data['status'] === 'suspended') {
                $this->keepOneSuperAdmin($target);
                DB::table('sessions')->where('user_id', $target->id)->delete(); // signed out everywhere, now
            }
            ActivityLog::record($request, 'user.'.$data['status'], ($data['status'] === 'active' ? 'Restored ' : 'Suspended ').$target->name, $target->company_id);
        }

        $target->update($data);

        return response()->json(['user' => self::shape($target, $request->user())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $target = $this->target($request, $id);
        $this->keepOneSuperAdmin($target);

        DB::table('sessions')->where('user_id', $target->id)->delete();
        ActivityLog::record($request, 'user.removed', "Removed {$target->name} ({$target->email})", $target->company_id);
        $target->delete();

        return response()->json(['ok' => true]);
    }

    public function invite(Request $request): JsonResponse
    {
        $scope = $this->scope($request, $request->input('company_id'));
        $actor = $request->user();
        abort_unless($this->canManage($actor, $scope), 403, 'Your role does not allow this.');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(Roles::validFor($scope))],
        ], ['email.unique' => 'Someone with this email already has an account.']);

        // Only staff can name a company's owner.
        abort_if($data['role'] === 'owner' && ! $actor->isStaff(), 422, 'Only BlessedPay staff can invite an owner.');

        Invitation::where('email', $data['email'])->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $invitation = new Invitation([
            'company_id' => $scope,
            'email' => strtolower($data['email']),
            'role' => $data['role'],
            'invited_by' => $actor->id,
        ]);
        $token = $invitation->refreshToken();

        ActivityLog::record($request, 'invite.sent', "Invited {$invitation->email} as ".Roles::label($invitation->role), $scope);

        return response()->json(['invitation' => self::inviteShape($invitation), 'link' => $this->deliver($invitation, $token)], 201);
    }

    public function resendInvite(Request $request, int $id): JsonResponse
    {
        $invitation = $this->openInvite($request, $id);
        $token = $invitation->refreshToken(); // the old link stops working

        ActivityLog::record($request, 'invite.resent', "Sent a new invitation link to {$invitation->email}", $invitation->company_id);

        return response()->json(['invitation' => self::inviteShape($invitation), 'link' => $this->deliver($invitation, $token)]);
    }

    public function revokeInvite(Request $request, int $id): JsonResponse
    {
        $invitation = $this->openInvite($request, $id);
        $invitation->update(['revoked_at' => now()]);
        ActivityLog::record($request, 'invite.revoked', "Revoked the invitation for {$invitation->email}", $invitation->company_id);

        return response()->json(['ok' => true]);
    }

    /** Public: what the invitation page shows before a password is chosen. */
    public function showInvite(string $token): JsonResponse
    {
        $invitation = Invitation::findByToken($token);
        abort_unless($invitation?->isOpen(), 404, 'This invitation has expired or was already used. Ask for a new one.');

        return response()->json([
            'email' => $invitation->email,
            'role_label' => Roles::label($invitation->role),
            'company' => $invitation->company?->name,
        ]);
    }

    /** Public: accept an invitation by choosing a name and password. Single use. */
    public function acceptInvite(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = DB::transaction(function () use ($token, $data) {
            $invitation = Invitation::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            abort_unless($invitation?->isOpen(), 404, 'This invitation has expired or was already used. Ask for a new one.');
            abort_if(User::where('email', $invitation->email)->exists(), 422, 'This email already has an account. Sign in instead.');

            $invitation->update(['accepted_at' => now()]);

            return User::create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
                'company_id' => $invitation->company_id,
                'role' => $invitation->role,
                'status' => 'active',
            ])->forceFill(['email_verified_at' => now()]);
        });
        $user->save();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        ActivityLog::record($request, 'invite.accepted', 'Joined by invitation as '.Roles::label($user->role), $user->company_id, $user);

        return response()->json(AuthController::session($user));
    }

    /** null = staff. A member is always pinned to their own company. */
    private function scope(Request $request, mixed $companyId): ?int
    {
        $user = $request->user();

        if (! $user->isStaff()) {
            return $user->company_id;
        }

        if ($companyId === null || $companyId === '') {
            return null;
        }

        return $this->company($request, (int) $companyId)->id;
    }

    private function canManage(User $actor, ?int $scope): bool
    {
        return $scope === null ? $actor->may('staff.manage') : $actor->may('team.manage') && $actor->reaches($scope);
    }

    /** The person being changed: reachable, manageable, not yourself, and not an owner unless you are staff. */
    private function target(Request $request, int $id): User
    {
        $actor = $request->user();
        $target = User::find($id);

        abort_unless($target && ($target->isStaff() ? $actor->isStaff() : $actor->reaches($target->company_id)), 404);
        abort_unless($this->canManage($actor, $target->company_id), 403, 'Your role does not allow this.');
        abort_if($target->id === $actor->id, 422, 'You cannot change your own account here.');
        abort_if($target->role === 'owner' && ! $actor->isStaff(), 422, 'Only BlessedPay staff can change a company owner.');

        return $target;
    }

    private function keepOneSuperAdmin(User $target): void
    {
        if ($target->role === 'super_admin' && $target->status === 'active') {
            $others = User::whereNull('company_id')->where('role', 'super_admin')->where('status', 'active')->whereKeyNot($target->id)->count();
            abort_if($others === 0, 422, 'There must always be at least one active super admin.');
        }
    }

    private function openInvite(Request $request, int $id): Invitation
    {
        $invitation = Invitation::find($id);
        $actor = $request->user();

        abort_unless($invitation && ($invitation->company_id === null ? $actor->isStaff() : $actor->reaches($invitation->company_id)), 404);
        abort_unless($this->canManage($actor, $invitation->company_id), 403, 'Your role does not allow this.');
        abort_unless($invitation->accepted_at === null && $invitation->revoked_at === null, 422, 'This invitation is no longer open.');

        return $invitation;
    }

    /**
     * Email the link, and also hand it back so the inviter can pass it on by
     * WhatsApp — mail may not be set up yet, and a failed send must not lose
     * the invitation.
     */
    private function deliver(Invitation $invitation, string $token): string
    {
        $link = url('/console/invite/'.$token);
        $where = $invitation->company?->name ?? 'BlessedPay staff';

        try {
            Mail::raw(
                "You have been invited to BlessedPay ({$where}) as ".Roles::label($invitation->role).".\n\n".
                'Choose your password here (the link works once and expires in '.Invitation::DAYS_VALID." days):\n{$link}\n\n".
                'If you were not expecting this, ignore this email.',
                fn ($message) => $message->to($invitation->email)->subject('Your BlessedPay invitation'),
            );
        } catch (Throwable $e) {
            report($e);
        }

        return $link;
    }

    private static function shape(User $u, User $viewer): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'role_label' => Roles::label($u->role),
            'status' => $u->status,
            'is_you' => $u->id === $viewer->id,
            'last_active_at' => $u->last_active_at?->toIso8601String(),
        ];
    }

    private static function inviteShape(Invitation $i): array
    {
        return [
            'id' => $i->id,
            'email' => $i->email,
            'role' => $i->role,
            'role_label' => Roles::label($i->role),
            'invited_by' => $i->inviter?->name,
            'expires_at' => $i->expires_at?->toIso8601String(),
            'expired' => $i->expires_at?->isPast() ?? false,
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}

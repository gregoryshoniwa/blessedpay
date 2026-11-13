<?php

namespace App\Support;

/**
 * Who may do what. Roles are fixed (not editable in the console) so that
 * "Operations" or "Finance" means the same thing to everyone.
 *
 * Staff roles belong to BlessedPay's own people (users.company_id is null) and
 * reach every company. Company roles belong to a company's members and reach
 * only that company.
 */
final class Roles
{
    public const STAFF = [
        'super_admin' => 'Super admin',
        'operations' => 'Operations',
        'support' => 'Support',
        'auditor' => 'Auditor',
    ];

    public const COMPANY = [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'finance' => 'Finance',
        'developer' => 'Developer',
        'viewer' => 'Viewer',
    ];

    /** ability => roles that have it */
    private const ABILITIES = [
        'payments.view' => ['super_admin', 'operations', 'support', 'auditor', 'owner', 'admin', 'finance', 'developer', 'viewer'],
        'payments.export' => ['super_admin', 'operations', 'auditor', 'owner', 'admin', 'finance'],
        'callbacks.resend' => ['super_admin', 'operations', 'owner', 'admin', 'developer'],
        'companies.manage' => ['super_admin', 'operations'],
        'company.edit' => ['super_admin', 'operations', 'owner', 'admin'],
        'keys.view' => ['super_admin', 'operations', 'owner', 'admin', 'developer'],
        'keys.manage' => ['super_admin', 'owner', 'admin', 'developer'],
        'team.view' => ['super_admin', 'operations', 'support', 'auditor', 'owner', 'admin'],
        'team.manage' => ['super_admin', 'operations', 'owner', 'admin'],
        'staff.manage' => ['super_admin'],
        'charges.view' => ['super_admin', 'operations', 'support', 'auditor', 'owner', 'admin', 'finance'],
        'charges.manage' => ['super_admin', 'operations'],
        'activity.view' => ['super_admin', 'operations', 'auditor', 'owner', 'admin'],
    ];

    public static function label(string $role): string
    {
        return self::STAFF[$role] ?? self::COMPANY[$role] ?? $role;
    }

    public static function allows(string $role, string $ability): bool
    {
        return in_array($role, self::ABILITIES[$ability] ?? [], true);
    }

    /** @return string[] */
    public static function abilitiesFor(string $role): array
    {
        return array_keys(array_filter(self::ABILITIES, fn (array $roles) => in_array($role, $roles, true)));
    }

    /** Roles valid for a staff member (company null) or a company member. */
    public static function validFor(?int $companyId): array
    {
        return array_keys($companyId === null ? self::STAFF : self::COMPANY);
    }
}

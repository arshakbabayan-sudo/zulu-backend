<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap 10.06 §7 — seed the company TEAM role catalog.
 *
 * The Add-employee modal has offered `company_operator` / `company_viewer`
 * since Bucket D, but those roles were never seeded — in prod the roles table
 * holds only super_admin / platform_admin / company_admin / agent, so adding
 * an employee with either role 422s with "Role not found". Arshak also asked
 * for a "Manager" (ղեկավար) role (§7).
 *
 * Seeds three company-scoped roles:
 *   • company_manager  (rank 3) — starts with the company_admin permission set;
 *     the RANK is the differentiator (cannot touch the rank-4 owner, cannot
 *     grant company_admin). Tune permissions in the RBAC tree as needed.
 *   • company_operator (rank 2) — operational set: company_admin minus
 *     owner/manager concerns (team mgmt, company profile, seller permissions,
 *     my-company/HR/settings sections).
 *   • company_viewer   (rank 1) — read-only: the `.view` subset.
 *
 * Permission sets are STARTING POINTS, applied only when the role is new or
 * empty — re-running never overwrites an admin-tuned role. The §7 RBAC guard
 * (company scope ⇒ no platform.*) applies to all three.
 */
return new class extends Migration
{
    private const OPERATOR_EXCLUDED = [
        'company.users.manage',
        'companies.edit_profile',
        'companies.manage_seller_permissions',
        'my_company.view',
        'hr.view',
        'settings.view',
        'management.view',
    ];

    public function up(): void
    {
        $now = now();

        $adminPermissionIds = [];
        $adminRoleId = DB::table('roles')->where('name', 'company_admin')->value('id');
        if ($adminRoleId !== null) {
            $adminPermissionIds = DB::table('role_permissions')
                ->where('role_id', $adminRoleId)
                ->pluck('permission_id')
                ->all();
        }

        $permissionNames = DB::table('permissions')
            ->whereIn('id', $adminPermissionIds)
            ->pluck('name', 'id');

        $sets = [
            'company_manager' => $adminPermissionIds,
            'company_operator' => array_keys(array_filter(
                $permissionNames->all(),
                fn (string $name) => ! in_array($name, self::OPERATOR_EXCLUDED, true)
            )),
            'company_viewer' => array_keys(array_filter(
                $permissionNames->all(),
                fn (string $name) => str_ends_with($name, '.view')
                    && ! in_array($name, self::OPERATOR_EXCLUDED, true)
            )),
        ];

        foreach ($sets as $roleName => $permissionIds) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if ($roleId === null) {
                $roleId = DB::table('roles')->insertGetId([
                    'name' => $roleName,
                    'scope' => 'company',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('roles')->where('id', $roleId)->update(['scope' => 'company', 'updated_at' => $now]);
            }

            // Starting set only for new/empty roles — never overwrite tuning.
            $hasPerms = DB::table('role_permissions')->where('role_id', $roleId)->exists();
            if ($hasPerms || $permissionIds === []) {
                continue;
            }

            DB::table('role_permissions')->insert(array_map(fn ($pid) => [
                'role_id' => $roleId,
                'permission_id' => $pid,
                'created_at' => $now,
                'updated_at' => $now,
            ], $permissionIds));
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: removing roles that may have live memberships
        // would orphan users. Delete manually via the RBAC page if ever needed.
    }
};

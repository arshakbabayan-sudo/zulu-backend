<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RBAC #2 — Phase 1 of "real per-action permission enforcement": grant the
 * operator/agent owner roles the WRITE (create/update/delete/manage) permissions
 * for their OWN-COMPANY data, so that the subsequent `permission:` route gates
 * can be applied without 403'ing a legitimate owner.
 *
 * Arshak approved 2026-06-05: operators/agents may perform create/change/delete
 * on THEIR OWN data (their bookings, invoices, payments, inventory, packages,
 * commissions, settlements); platform-level actions stay super/platform-only.
 *
 * This is purely ADDITIVE (insert-if-missing) — granting a permission can never
 * 403 anyone — and idempotent. Pivot rows are inserted WITHOUT an explicit id so
 * Postgres' role_permissions_id_seq fills it (avoids the seed-insert sequence
 * trap, feedback_pg_sequence_seed_migration.md).
 *
 * company_admin (operator OWNER) → EVERY non-`platform.*` permission EXCEPT the
 * super/platform-only ones below. The backend already tenant-scopes every
 * resource to the caller's companies (RBAC Phases 0-6), so this only widens the
 * owner's reach into THEIR OWN company's data — never cross-tenant. `*.view_all`
 * included on purpose (owner sees the whole company).
 *
 * Explicitly NOT granted to company_admin (kept super/platform-only):
 *   - any `platform.*`            → isPlatformAdmin escalator (would = super)
 *   - super_admin
 *   - localization.manage         → translations are platform-level
 *   - reviews.moderate            → moderation is platform-level (Arshak)
 *   - payments.refund             → sensitive; goes through admin-approval (#7)
 *   - companies.manage_seller_permissions → platform decides selling rights
 *
 * agent → its OWN sales operations only (bookings / package-orders / invoices /
 * payment capture+fail / package delete / own finance views). Agents resell, so
 * they do NOT get inventory create/update/delete (hotels/flights/…) or commission
 * policy writes (operators set those).
 */
return new class extends Migration
{
    /** Super/platform-only perms that company_admin must NOT receive. */
    private const COMPANY_ADMIN_EXCLUDED = [
        'super_admin',
        'localization.manage',
        'reviews.moderate',
        'payments.refund',
        'companies.manage_seller_permissions',
    ];

    /** Own-sales write perms added to the agent role (additive to its views). */
    private const AGENT_ADD = [
        'bookings.view', 'bookings.create', 'bookings.confirm', 'bookings.cancel',
        'package_orders.view', 'package_orders.manage',
        'invoices.create', 'invoices.issue', 'invoices.pay', 'invoices.cancel',
        'payments.capture', 'payments.fail',
        'packages.delete',
        'finance.entitlements.view', 'finance.settlements.view',
    ];

    public function up(): void
    {
        // company_admin: every non-platform perm except the super-only excludes.
        $companyAdminId = DB::table('roles')->where('name', 'company_admin')->value('id');
        if ($companyAdminId !== null) {
            $permIds = DB::table('permissions')
                ->where('name', 'not like', 'platform.%')
                ->whereNotIn('name', self::COMPANY_ADMIN_EXCLUDED)
                ->pluck('id');
            $this->grant($companyAdminId, $permIds->all());
        }

        // agent: explicit own-sales write set.
        $agentId = DB::table('roles')->where('name', 'agent')->value('id');
        if ($agentId !== null) {
            $permIds = DB::table('permissions')
                ->whereIn('name', self::AGENT_ADD)
                ->pluck('id');
            $this->grant($agentId, $permIds->all());
        }
    }

    /**
     * @param  array<int, int>  $permissionIds
     */
    private function grant(int $roleId, array $permissionIds): void
    {
        $now = now();
        foreach ($permissionIds as $permId) {
            $exists = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permId)
                ->exists();
            if (! $exists) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Restore the pre-migration state: company_admin keeps only the prior
        // keystone VIEW set; agent loses the AGENT_ADD perms.
        $keystoneKeep = [
            'bookings.view', 'commission_records.view', 'commissions.view',
            'companies.view', 'companies.view_dashboard', 'finance.entitlements.view',
            'finance.settlements.view', 'invoices.view', 'package_orders.view',
            'payments.pay', 'payments.view', 'reviews.view',
        ];

        $companyAdminId = DB::table('roles')->where('name', 'company_admin')->value('id');
        if ($companyAdminId !== null) {
            $removeIds = DB::table('permissions')
                ->where('name', 'not like', 'platform.%')
                ->whereNotIn('name', self::COMPANY_ADMIN_EXCLUDED)
                ->whereNotIn('name', $keystoneKeep)
                ->pluck('id');
            DB::table('role_permissions')
                ->where('role_id', $companyAdminId)
                ->whereIn('permission_id', $removeIds)
                ->delete();
        }

        $agentId = DB::table('roles')->where('name', 'agent')->value('id');
        if ($agentId !== null) {
            $removeIds = DB::table('permissions')->whereIn('name', self::AGENT_ADD)->pluck('id');
            DB::table('role_permissions')
                ->where('role_id', $agentId)
                ->whereIn('permission_id', $removeIds)
                ->delete();
        }
    }
};

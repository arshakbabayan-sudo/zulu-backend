<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;

/**
 * Phase R.1 (2026-05-28) — fix corrupted role → permission defaults.
 *
 * Live audit found company-scoped roles had been granted platform-level
 * escalators: `company_admin` carried `super_admin` (→ full super-admin),
 * `agent` carried `platform.manage` + platform.* (→ platform-admin), while
 * `operator_admin` had only 7 permissions. AdminAccessService treats
 * `super_admin` / `platform.*` as a full unlock, so any operator/agent login
 * was a de-facto super-admin and permission toggles changed nothing.
 *
 * This re-syncs every role to its intended default set (mirrors
 * RbacBootstrapSeeder's company/platform split, with Arshak's 2026-05-28
 * refinement of the agent role: view + booking + assemble-package + save/
 * prepay, but NO raw inventory create/delete and NO platform.*). Sets are
 * computed from the live permissions table so new permissions are covered.
 *
 * Idempotent (sync). See docs/audits/rbac-access-map-2026-05-28.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var Collection<string,int> $byName name => id */
        $byName = Permission::query()->pluck('id', 'name');
        if ($byName->isEmpty()) {
            return;
        }

        $names = $byName->keys()->all();

        // Company-scoped = everything except platform.* and the escalators.
        $companyScoped = array_values(array_filter(
            $names,
            fn (string $n) => ! str_starts_with($n, 'platform.')
                && ! in_array($n, ['super_admin', 'localization.manage'], true)
        ));

        // Platform oversight set (platform_admin).
        $platformSet = array_values(array_filter(
            $names,
            fn (string $n) => str_starts_with($n, 'platform.')
                || in_array($n, ['localization.view', 'localization.manage', 'reviews.moderate'], true)
        ));

        // company_operator = company_admin minus employee + finance-management.
        $operationalExclude = ['company.users.manage', 'finance.entitlements.manage', 'finance.settlements.manage'];
        $companyOperator = array_values(array_diff($companyScoped, $operationalExclude));

        // Agent: view catalog + assemble package from existing products +
        // book / prepay / save-as-favourite. No inventory CRUD, no platform.*.
        $agentSet = array_values(array_intersect([
            'hotels.view', 'flights.view', 'cars.view', 'transfers.view', 'excursions.view', 'visas.view',
            'packages.view', 'packages.create', 'packages.edit', 'packages.manage_components',
            'package_orders.view', 'package_orders.manage',
            'bookings.view', 'bookings.create',
            'payments.view', 'payments.create', 'payments.pay',
            'saved_items.manage',
            'offers.view', 'invoices.view', 'commissions.view', 'commission_records.view',
            'reviews.create', 'reviews.view',
            'companies.view', 'seller_permissions.view', 'account.update_profile',
        ], $names));

        $idsFor = fn (array $ns): array => $byName->only($ns)->values()->all();

        $map = [
            'super_admin' => $byName->values()->all(), // all permissions
            'platform_admin' => $idsFor($platformSet),
            'company_admin' => $idsFor($companyScoped),
            'operator_admin' => $idsFor($companyScoped),
            'company_operator' => $idsFor($companyOperator),
            'agent' => $idsFor($agentSet),
        ];

        foreach ($map as $roleName => $ids) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role !== null) {
                $role->permissions()->sync($ids);
            }
        }
    }

    public function down(): void
    {
        // No-op — the previous (corrupted) escalated state must not be restored.
    }
};

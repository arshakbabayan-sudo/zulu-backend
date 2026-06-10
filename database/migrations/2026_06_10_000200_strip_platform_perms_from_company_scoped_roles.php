<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap 10.06 §7 — strip stray `platform.*` / escalator permissions from
 * company-scoped roles, this time keyed on roles.scope so future tenant
 * roles are covered too.
 *
 * 2026_06_05_000050 already stripped platform.* from the legacy tenant role
 * names, but the grants came back: PUT rbac/roles/{role}/permissions synced
 * whatever the RBAC tree submitted, and the tree's Dashboard section carries
 * platform.stats.view — so saving the Operator/Agent role from the UI
 * re-attached it. On 2026-06-10 both company_admin and agent held
 * platform.stats.view in prod again, which makes
 * AdminAccessService::isPlatformAdmin() classify every operator/agent as
 * platform staff (canonical_role platform_admin) and bypass tenant scoping.
 *
 * The companion AdminRbacController guard closes the re-pollution vector;
 * this migration cleans what is already in the table. Idempotent.
 */
return new class extends Migration
{
    /** Tenant role names from before roles.scope existed, in case scope is NULL. */
    private const LEGACY_TENANT_ROLE_NAMES = ['company_admin', 'company_operator', 'operator_admin', 'agent'];

    /** Non-prefixed names AdminAccessService treats as full-platform unlock. */
    private const ESCALATOR_PERMISSIONS = ['super_admin', 'platform.admin', 'platform.manage'];

    public function up(): void
    {
        $roleIds = DB::table('roles')
            ->where(function ($query): void {
                $query->where('scope', 'company')
                    ->orWhereIn('name', self::LEGACY_TENANT_ROLE_NAMES);
            })
            ->pluck('id');
        if ($roleIds->isEmpty()) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where(function ($query): void {
                $query->where('name', 'like', 'platform.%')
                    ->orWhereIn('name', self::ESCALATOR_PERMISSIONS);
            })
            ->pluck('id');
        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_permissions')
            ->whereIn('role_id', $roleIds->all())
            ->whereIn('permission_id', $permissionIds->all())
            ->delete();
    }

    public function down(): void
    {
        // Intentionally NOT reversible: re-granting platform.* to company-scoped
        // roles is the misconfiguration this migration fixes. No-op.
    }
};

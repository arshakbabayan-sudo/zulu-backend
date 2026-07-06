<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RBAC numbers unification (2026-07-06) — detach the DEAD `hr.view` grants.
 *
 * The HR sidebar section was folded into CRM on 2026-06-06 (see the #4 note in
 * AdminRbacController::PERMISSION_TREE) and `hr.view` was removed from the
 * permission tree — but the grants stayed behind in `role_permissions`
 * (agent + company_admin from the RbacBootstrapSeeder / the
 * 2026_06_06_000040 section-gate migration, company team roles from
 * 2026_06_10_000300). A full consumer sweep (2026-07-06) found NOTHING that
 * checks it at runtime: no `permission:hr.view` route middleware, no
 * controller/AdminAccessService lookup, no zulu-admin-next access check —
 * the orphaned grants only inflated the role permission counts on the
 * Settings → Permissions page.
 *
 * This detaches `hr.view` from every role. The permission ROW itself is kept
 * (audit trail, and per-user UserPermissionOverride rows are untouched) —
 * only the role attachments go. Idempotent.
 */
return new class extends Migration
{
    /** Non-tree permissions confirmed dead (no runtime consumer anywhere). */
    private const DEAD_PERMISSION_NAMES = ['hr.view'];

    public function up(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', self::DEAD_PERMISSION_NAMES)
            ->pluck('id')
            ->all();

        if ($ids !== []) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        }
    }

    public function down(): void
    {
        // Intentional no-op — the detached grants were dead (nothing consumed
        // them), so there is no behavior to restore. Re-grant via the RBAC
        // page if the permission is ever revived.
    }
};

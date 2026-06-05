<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RBAC #2 — strip stray `platform.*` permissions from the tenant roles.
 *
 * AdminAccessService::isPlatformAdmin() returns true for ANY user holding a
 * `platform.*` permission (hasPermissionPrefix('platform.')), and isPlatformAdmin
 * users BYPASS every tenant permission/scope gate (CheckPermission middleware +
 * allowsCommerceOperatorAccess both short-circuit on it). RbacBootstrapSeeder
 * deliberately excludes platform.* from the company_admin / operator_admin / agent
 * roles for exactly this reason — but in production the `agent` role had picked up
 * `platform.stats.view` post-seed, so agents were silently treated as platform
 * staff and bypassed all enforcement (found while verifying the finance gates).
 *
 * Remove every `platform.*` grant from the three tenant roles so they are gated as
 * real tenants. Super-admin and the genuine `platform_admin` role are untouched.
 * Idempotent; safe to re-run.
 */
return new class extends Migration
{
    private const TENANT_ROLES = ['company_admin', 'operator_admin', 'agent'];

    public function up(): void
    {
        $roleIds = DB::table('roles')->whereIn('name', self::TENANT_ROLES)->pluck('id');
        if ($roleIds->isEmpty()) {
            return;
        }

        $platformPermIds = DB::table('permissions')->where('name', 'like', 'platform.%')->pluck('id');
        if ($platformPermIds->isEmpty()) {
            return;
        }

        DB::table('role_permissions')
            ->whereIn('role_id', $roleIds->all())
            ->whereIn('permission_id', $platformPermIds->all())
            ->delete();
    }

    public function down(): void
    {
        // Intentionally NOT reversible: re-granting platform.* to tenant roles is
        // the misconfiguration this migration fixes. No-op.
    }
};

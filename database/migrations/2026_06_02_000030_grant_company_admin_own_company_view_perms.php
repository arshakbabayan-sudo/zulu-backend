<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RBAC keystone (2026-06-02) — give the company_admin (operator/agent OWNER)
 * role a baseline OWN-COMPANY view permission set.
 *
 * Problem found live: the company_admin role carried only 2 permissions
 * (companies.view_dashboard, payments.pay), so an operator owner saw almost
 * nothing — the Bookings / Finance sidebar sections never appeared (their
 * frontend predicates require bookings.view / commissions.view) and the pages
 * 403'd. Arshak's standing rule: an operator/agent sees their OWN sales,
 * customers, finances.
 *
 * The backend already tenant-scopes every one of these resources to the
 * caller's companies (RBAC blueprint Phases 0-6), so granting VIEW only widens
 * the owner's visibility into THEIR OWN data — never cross-tenant. We grant
 * VIEW + the read-side finance/booking perms here; create/confirm/manage
 * capabilities are a separate (capability) decision and are NOT granted.
 *
 * Explicitly NOT granted: any `platform.*` permission (those make
 * isPlatformAdmin() true = cross-tenant/super access). Idempotent.
 */
return new class extends Migration
{
    private const OWNER_VIEW_PERMISSIONS = [
        // Bookings (their sales)
        'bookings.view',
        'package_orders.view',
        // Finance (their money)
        'invoices.view',
        'payments.view',
        'commissions.view',
        'commission_records.view',
        'finance.entitlements.view',
        'finance.settlements.view',
        // Their company + reviews
        'companies.view',
        'reviews.view',
    ];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'company_admin')->value('id');
        if ($roleId === null) {
            return;
        }

        $now = now();
        foreach (self::OWNER_VIEW_PERMISSIONS as $name) {
            $permId = DB::table('permissions')->where('name', $name)->value('id');
            if ($permId === null) {
                continue; // skip perms that don't exist in this environment
            }

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
        $roleId = DB::table('roles')->where('name', 'company_admin')->value('id');
        if ($roleId === null) {
            return;
        }
        $permIds = DB::table('permissions')->whereIn('name', self::OWNER_VIEW_PERMISSIONS)->pluck('id');
        DB::table('role_permissions')->where('role_id', $roleId)->whereIn('permission_id', $permIds)->delete();
    }
};

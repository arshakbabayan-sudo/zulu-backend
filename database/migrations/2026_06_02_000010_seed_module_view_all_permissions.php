<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 Layer-B — seed the per-module "see the whole company" permissions.
 *
 * Default: a plain employee sees only their own rows for a module
 * (attribution_col = their id). Granting them one of these via the employee
 * permission drawer promotes them to whole-company view FOR THAT MODULE
 * (AdminAccessService::employeeRowScopeUserId returns null → no row filter).
 * Company owners (company_admin role) get whole-company view by right and do
 * not need these.
 *
 * Only the modules that actually carry an attribution column today are seeded
 * (bookings/package_orders → orders.sold_by_user_id, crm → owner_user_id,
 * contracts → created_by_user_id). More modules join as their attribution
 * columns land.
 *
 * The employee drawer (CompanyRbacController::assignablePermissions) lists any
 * non-`platform.*` permission, so creating these rows is enough to make them
 * grantable — no extra wiring needed. Not assigned to any role by default
 * (owners bypass via role; employees get them explicitly).
 */
return new class extends Migration
{
    private const VIEW_ALL_PERMISSIONS = [
        'bookings.view_all',
        'package_orders.view_all',
        'crm.view_all',
        'contracts.view_all',
    ];

    public function up(): void
    {
        // Prod (pgsql) gotcha: the permissions.id sequence trailed MAX(id)
        // because earlier seeders inserted rows with explicit ids without
        // advancing it — so a fresh INSERT here collided (SQLSTATE 23505,
        // "Key (id)=(60) already exists"). Realign the sequence to MAX(id)
        // first; this both unblocks our inserts and repairs the sequence for
        // every future permission insert. No-op on sqlite (test DB).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "SELECT setval(pg_get_serial_sequence('permissions', 'id'), GREATEST((SELECT MAX(id) FROM permissions), 1))"
            );
        }

        $now = now();
        foreach (self::VIEW_ALL_PERMISSIONS as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['name' => $name, 'updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', self::VIEW_ALL_PERMISSIONS)->delete();
    }
};

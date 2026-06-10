<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §7 sweep follow-up (2026-06-11) — restore the agent role's finance/commission
 * READ permissions.
 *
 * The 2026-06-05 finance/commission gating was designed with "operator+agent
 * hold the .view perms" (see the route comments), but the 4-role
 * RbacBootstrapSeeder redo (2026-06-06) rebuilt `agent` with section-view
 * gates only — dropping these four. Nobody noticed because the platform-perm
 * pollution made agents BYPASS every gate; after the 2026-06-10 pollution fix
 * the gates actually apply and the agent's Finance/Commissions pages would 403.
 *
 * Idempotent: only inserts missing role_permissions rows.
 */
return new class extends Migration
{
    private const AGENT_VIEW_PERMS = [
        'finance.entitlements.view',
        'finance.settlements.view',
        'commissions.view',
        'commission_records.view',
    ];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'agent')->value('id');
        if ($roleId === null) {
            return;
        }

        $now = now();
        $permissionIds = DB::table('permissions')
            ->whereIn('name', self::AGENT_VIEW_PERMS)
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            $exists = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();
            if (! $exists) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Read-permission grant; reverting would re-break agent Finance pages. No-op.
    }
};

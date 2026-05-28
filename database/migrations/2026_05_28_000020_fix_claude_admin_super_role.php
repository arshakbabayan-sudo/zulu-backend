<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix-up for 2026_05_28_000010: the claude.admin user was created but the
 * super_admin membership wasn't, because the platform-company lookup
 * (companies.type = 'platform') returned null on prod, so the link step was
 * skipped → is_super_admin computed False.
 *
 * `is_super_admin` is a computed accessor: it's true when the user has ANY
 * user_company membership whose role is name='super_admin' AND scope='platform'
 * (User::isSuperAdmin). The company doesn't matter for the flag — so here we
 * link to whatever company an existing super_admin already uses, else the
 * platform company, else the first company. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $userId = DB::table('users')->where('email', 'claude.admin@zulu.am')->value('id');
        if (! $userId) {
            return; // 000010 should have created it; nothing to do otherwise.
        }

        $roleId = DB::table('roles')
            ->where('name', 'super_admin')
            ->where('scope', 'platform')
            ->value('id');
        if (! $roleId) {
            return; // no platform super_admin role — cannot elevate safely.
        }

        // Pick a company: mirror an existing super_admin membership, else the
        // platform company, else the first company available.
        $companyId = DB::table('user_company')->where('role_id', $roleId)->value('company_id')
            ?? DB::table('companies')->where('type', 'platform')->value('id')
            ?? DB::table('companies')->orderBy('id')->value('id');

        if (! $companyId) {
            return; // no company at all — should never happen on prod.
        }

        $existing = DB::table('user_company')
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->first();

        if ($existing) {
            DB::table('user_company')
                ->where('id', $existing->id)
                ->update(['role_id' => $roleId, 'updated_at' => now()]);
        } else {
            DB::table('user_company')->insert([
                'user_id' => $userId,
                'company_id' => $companyId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No-op: leave the membership; 000010's down() removes the user.
    }
};

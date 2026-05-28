<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase verification (2026-05-28) — dedicated automation super-admin so Claude
 * can log into admin.zulu.am via Playwright and self-verify deploys.
 *
 * Requested by the product owner: "create a super-admin for yourself, keep it,
 * always use it." Idempotent (firstOrCreate by email) — re-running on every
 * deploy never duplicates or resets the account. Password hash only lives in
 * this (private) repo; the plaintext is kept in Claude's local memory file.
 */
return new class extends Migration
{
    public function up(): void
    {
        $email = 'claude.admin@zulu.am';
        // bcrypt of the plaintext stored in Claude memory (not in git).
        $hash = '$2y$10$nnfGR9GUL4dUbDb3AaJXvujtlUqonaG14VNg9OOqsSwzgEpTX4EBO';

        $existing = DB::table('users')->where('email', $email)->first();
        if ($existing) {
            $userId = $existing->id;
        } else {
            $userId = DB::table('users')->insertGetId([
                'name' => 'Claude Admin (automation)',
                'email' => $email,
                'password' => $hash,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Link to the platform company with the super_admin role (mirrors
        // TestUsersSeeder). Guard each lookup so a missing row never breaks
        // the deploy migration.
        $platformCompanyId = DB::table('companies')->where('type', 'platform')->value('id');
        $superAdminRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');

        if ($platformCompanyId && $superAdminRoleId) {
            $link = DB::table('user_company')
                ->where('user_id', $userId)
                ->where('company_id', $platformCompanyId)
                ->first();
            if ($link) {
                DB::table('user_company')
                    ->where('id', $link->id)
                    ->update(['role_id' => $superAdminRoleId, 'updated_at' => now()]);
            } else {
                DB::table('user_company')->insert([
                    'user_id' => $userId,
                    'company_id' => $platformCompanyId,
                    'role_id' => $superAdminRoleId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('users')->where('email', 'claude.admin@zulu.am')->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase R.5 (2026-05-28) — dedicated automation accounts for verifying the
 * operator/agent experience via Playwright (super-admin bypasses all gates,
 * so role-scoped accounts are required to confirm menu/button enforcement).
 *
 * Both land in the "ZULU Test Agency" tenant. Idempotent (firstOrCreate by
 * email). Hashes only in this private repo; plaintext in Claude memory
 * (project_claude_admin_account.md). Mirrors the claude.admin migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $companyId = DB::table('companies')->where('name', 'ZULU Test Agency')->value('id')
            ?? DB::table('companies')->orderBy('id')->value('id');
        if (! $companyId) {
            return;
        }

        $accounts = [
            [
                'email' => 'claude.operator@zulu.am',
                'name' => 'Claude Operator (automation)',
                'hash' => '$2y$10$Akh1ie/SCpRkei3sa.vCz.pPMwcI8sNJGesTRMlwM6xds1mweihhS',
                'role' => 'company_admin',
            ],
            [
                'email' => 'claude.agent@zulu.am',
                'name' => 'Claude Agent (automation)',
                'hash' => '$2y$10$8.5IkCQIf.860Y4s5.l2NOnV2rcCQp25.zUzbqMuB1EHXDIwva6Zm',
                'role' => 'agent',
            ],
        ];

        foreach ($accounts as $acc) {
            $roleId = DB::table('roles')->where('name', $acc['role'])->value('id');
            if (! $roleId) {
                continue;
            }

            $existing = DB::table('users')->where('email', $acc['email'])->first();
            if ($existing) {
                $userId = $existing->id;
            } else {
                $userId = DB::table('users')->insertGetId([
                    'name' => $acc['name'],
                    'email' => $acc['email'],
                    'password' => $acc['hash'],
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $link = DB::table('user_company')
                ->where('user_id', $userId)
                ->where('company_id', $companyId)
                ->first();
            if ($link) {
                DB::table('user_company')->where('id', $link->id)
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
    }

    public function down(): void
    {
        DB::table('users')->whereIn('email', ['claude.operator@zulu.am', 'claude.agent@zulu.am'])->delete();
    }
};

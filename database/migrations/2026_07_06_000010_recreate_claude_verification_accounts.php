<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 2026-07-06 — recreate the three Claude verification accounts that were
 * hard-deleted by the 2026-06-19 prod data wipe (ResetDataCommand keeps only
 * arshakbabayan@gmail.com). The original creation migrations
 * (2026_05_28_000010 / _000020 / _000050) are already recorded in the
 * migrations table, so they will never re-run — this migration replays their
 * exact logic (same emails, names, bcrypt hashes, roles, company lookups)
 * under a new timestamp.
 *
 * Accounts:
 *   - claude.admin@zulu.am    → super_admin on the platform company
 *   - claude.operator@zulu.am → company_admin in "ZULU Test Agency"
 *   - claude.agent@zulu.am    → agent in "ZULU Test Agency"
 *
 * Idempotent: firstOrCreate-by-email semantics; re-running is a no-op.
 * Defensive: every role/company lookup is guarded — a missing row logs a
 * line and skips instead of failing (CI runs on SQLite without prod seed
 * state, and FKs are enforced there). Hashes are the ORIGINAL ones from the
 * 2026_05_28 migrations; plaintexts live only in Claude's local memory file
 * (project_claude_admin_account.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->recreateSuperAdmin();
        $this->recreateOperatorAndAgent();
    }

    /**
     * Mirrors 2026_05_28_000010 + the _000020 fix-up: create the user, then
     * attach a platform-scoped super_admin membership. Company preference
     * (from _000020): a company an existing super_admin already uses, else
     * the platform company, else the first company.
     */
    private function recreateSuperAdmin(): void
    {
        $userId = $this->ensureUser(
            'claude.admin@zulu.am',
            'Claude Admin (automation)',
            // bcrypt hash reused verbatim from 2026_05_28_000010.
            '$2y$10$nnfGR9GUL4dUbDb3AaJXvujtlUqonaG14VNg9OOqsSwzgEpTX4EBO'
        );

        // Platform-scoped super_admin (per the _000020 fix: is_super_admin is
        // computed from role name + scope, not from the company). Fall back to
        // a name-only lookup so environments without the scope column value
        // still resolve (mirrors _000010).
        $roleId = DB::table('roles')
            ->where('name', 'super_admin')
            ->where('scope', 'platform')
            ->value('id')
            ?? DB::table('roles')->where('name', 'super_admin')->value('id');

        if (! $roleId) {
            Log::warning('recreate_claude_verification_accounts: super_admin role not found — skipping claude.admin membership.');

            return;
        }

        $companyId = DB::table('user_company')->where('role_id', $roleId)->value('company_id')
            ?? DB::table('companies')->where('type', 'platform')->value('id')
            ?? DB::table('companies')->orderBy('id')->value('id');

        if (! $companyId) {
            Log::warning('recreate_claude_verification_accounts: no company found — skipping claude.admin membership.');

            return;
        }

        $this->ensureMembership($userId, $companyId, $roleId);
    }

    /**
     * Mirrors 2026_05_28_000050: company_admin + agent accounts inside the
     * "ZULU Test Agency" tenant (fallback: first company).
     */
    private function recreateOperatorAndAgent(): void
    {
        $companyId = DB::table('companies')->where('name', 'ZULU Test Agency')->value('id')
            ?? DB::table('companies')->orderBy('id')->value('id');

        if (! $companyId) {
            Log::warning('recreate_claude_verification_accounts: no company found — skipping claude.operator/claude.agent.');

            return;
        }

        $accounts = [
            [
                'email' => 'claude.operator@zulu.am',
                'name' => 'Claude Operator (automation)',
                // bcrypt hash reused verbatim from 2026_05_28_000050.
                'hash' => '$2y$10$Akh1ie/SCpRkei3sa.vCz.pPMwcI8sNJGesTRMlwM6xds1mweihhS',
                'role' => 'company_admin',
            ],
            [
                'email' => 'claude.agent@zulu.am',
                'name' => 'Claude Agent (automation)',
                // bcrypt hash reused verbatim from 2026_05_28_000050.
                'hash' => '$2y$10$8.5IkCQIf.860Y4s5.l2NOnV2rcCQp25.zUzbqMuB1EHXDIwva6Zm',
                'role' => 'agent',
            ],
        ];

        foreach ($accounts as $acc) {
            $roleId = DB::table('roles')->where('name', $acc['role'])->value('id');
            if (! $roleId) {
                Log::warning("recreate_claude_verification_accounts: role '{$acc['role']}' not found — skipping {$acc['email']}.");

                continue;
            }

            $userId = $this->ensureUser($acc['email'], $acc['name'], $acc['hash']);
            $this->ensureMembership($userId, $companyId, $roleId);
        }
    }

    /**
     * firstOrCreate-by-email. New rows get status=active and a verified
     * email; existing rows are restored to active/verified without touching
     * the password (so a manually rotated password survives re-runs).
     */
    private function ensureUser(string $email, string $name, string $hash): int
    {
        $existing = DB::table('users')->where('email', $email)->first();

        if ($existing) {
            $restore = [];
            if ($existing->status !== 'active') {
                $restore['status'] = 'active';
            }
            if ($existing->email_verified_at === null) {
                $restore['email_verified_at'] = now();
            }
            if ($restore !== []) {
                $restore['updated_at'] = now();
                DB::table('users')->where('id', $existing->id)->update($restore);
            }

            return (int) $existing->id;
        }

        return (int) DB::table('users')->insertGetId([
            'name' => $name,
            'email' => $email,
            'password' => $hash,
            'status' => 'active',
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Ensure a user_company pivot row exists with the given role (update the
     * role on an existing link, insert otherwise) — same pattern as the
     * 2026_05_28 migrations.
     */
    private function ensureMembership(int $userId, int $companyId, int $roleId): void
    {
        $link = DB::table('user_company')
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->first();

        if ($link) {
            if ((int) $link->role_id !== $roleId) {
                DB::table('user_company')
                    ->where('id', $link->id)
                    ->update(['role_id' => $roleId, 'updated_at' => now()]);
            }
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
        // No-op (same pattern as 2026_05_28_000020): by now these automation
        // accounts may own audit-log / booking rows on prod, so a rollback
        // must not hard-delete them and risk FK violations. The original
        // _000010/_000050 down() methods already cover removal if ever needed.
    }
};

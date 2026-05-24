<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 / Step B.0 — add `scope` column to roles table.
 *
 * Distinguishes platform-scoped roles (super_admin, platform_admin,
 * operator_admin, agent_owner) from company-scoped roles (owner, booker,
 * finance, content, viewer, agent, company_admin). Required for the RBAC
 * scope whitelist that prevents non-super-admin callers from granting
 * platform-scoped roles via the role-assignment API.
 *
 * Idempotent + reversible. Backfills the 5 known production roles per the
 * audit (Phase 1 audit doc §7): super_admin / platform_admin /
 * operator_admin → platform; company_admin / agent → company. Any
 * future-added role defaults to 'company' (safer — explicit elevation to
 * 'platform' requires a follow-up update).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'scope')) {
            Schema::table('roles', function (Blueprint $table): void {
                // Default to 'company' so any pre-existing rows have a
                // safe value before the explicit backfill below.
                $table->string('scope', 16)->default('company')->after('name');
            });
        }

        // Explicit backfill of platform-scoped roles per Phase 1 audit §7.
        // operator_admin: confirmed platform-scope per product spec.
        // company_admin / agent: stay at the safer 'company' default.
        DB::table('roles')
            ->whereIn('name', ['super_admin', 'platform_admin', 'operator_admin', 'agent_owner'])
            ->update(['scope' => 'platform']);

        // CHECK constraint — PG-only syntax; safe on the prod (PostgreSQL)
        // and skipped on the SQLite test driver where CHECK is supported
        // but the named-constraint syntax differs.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $exists = (bool) DB::selectOne(
                "SELECT 1 FROM pg_constraint WHERE conname = 'roles_scope_chk'"
            );
            if (! $exists) {
                DB::statement(
                    "ALTER TABLE roles ADD CONSTRAINT roles_scope_chk CHECK (scope IN ('platform', 'company'))"
                );
            }
        }

        // Index for the role-scope whitelist lookup (Step B.2).
        if (! $this->indexExists('roles', 'roles_scope_idx')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->index('scope', 'roles_scope_idx');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_scope_chk');
        }

        if ($this->indexExists('roles', 'roles_scope_idx')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->dropIndex('roles_scope_idx');
            });
        }

        if (Schema::hasColumn('roles', 'scope')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->dropColumn('scope');
            });
        }
    }

    /**
     * Driver-agnostic index existence check (PG via pg_indexes, fallback
     * via doctrine schema manager for other drivers/test sqlite).
     */
    private function indexExists(string $table, string $index): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return (bool) DB::selectOne(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $index]
            );
        }

        // SQLite (test env)
        if ($driver === 'sqlite') {
            $row = DB::selectOne(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?",
                [$index]
            );

            return $row !== null;
        }

        // Fallback — pretend it doesn't exist so the create attempts and
        // any duplicate fails loudly rather than silently skipping.
        return false;
    }
};

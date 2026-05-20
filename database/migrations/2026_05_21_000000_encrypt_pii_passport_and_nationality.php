<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 (GDPR Critical) — encrypt passport_number + nationality at-rest.
 *
 * docs/audits/gdpr-compliance-2026-05-20.md flagged these PII columns
 * as plaintext-stored — Article 32 (Security of Processing) violation
 * blocking EU customer acceptance.
 *
 * Scope of this migration:
 *  - passengers.passport_number  (was varchar(50))
 *  - passengers.nationality      (was varchar(100))
 *  - visa_applications.passport_number (was varchar(255) — large enough already)
 *  - users.nationality           (was varchar(64))
 *
 * NOT scope of this migration (deferred — see followup roadmap):
 *  - passengers.date_of_birth    (column type `date`, can't hold encrypted string
 *                                  without API rework; Carbon `date` cast conflict)
 *  - users.birth_date            (same reason)
 *
 * Strategy:
 *  1. Drop the index on passport_number (was useless once encrypted — encrypted
 *     values are random per insert, so the index serves no lookup purpose).
 *  2. ALTER COLUMN ... TYPE TEXT for each varchar that's too small to hold a
 *     Laravel encrypted-string payload (base64 + IV + MAC ≈ 120+ chars even
 *     for a 10-char plaintext).
 *  3. Re-encrypt existing rows: read raw plaintext via DB::table() (bypassing
 *     the now-attached Eloquent cast), wrap with Crypt::encryptString(), write
 *     back via DB::table()->update(). Idempotent — skips rows that already
 *     look encrypted (Laravel encrypted strings start with the literal "eyJ"
 *     because they're base64-encoded JSON envelopes).
 *
 * Driver-aware: PG and SQLite both supported. SQLite doesn't need ALTER COLUMN
 * because it allows storing arbitrary string lengths regardless of declared
 * varchar size; PG enforces.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // 1. Drop the passport_number index (no longer useful after encryption).
        if (Schema::hasTable('passengers') && $this->hasIndex('passengers', 'passengers_passport_number_index')) {
            Schema::table('passengers', function (Blueprint $t): void {
                $t->dropIndex('passengers_passport_number_index');
            });
        }

        // 2. Widen column types where needed (Postgres only — SQLite is permissive).
        if ($driver === 'pgsql') {
            if (Schema::hasColumn('passengers', 'passport_number')) {
                DB::statement('ALTER TABLE passengers ALTER COLUMN passport_number TYPE TEXT');
            }
            if (Schema::hasColumn('passengers', 'nationality')) {
                DB::statement('ALTER TABLE passengers ALTER COLUMN nationality TYPE TEXT');
            }
            if (Schema::hasColumn('users', 'nationality')) {
                DB::statement('ALTER TABLE users ALTER COLUMN nationality TYPE TEXT');
            }
            if (Schema::hasColumn('visa_applications', 'passport_number')) {
                DB::statement('ALTER TABLE visa_applications ALTER COLUMN passport_number TYPE TEXT');
            }
        }

        // 3. Re-encrypt existing rows. Use raw DB queries to bypass Eloquent casts.
        $this->encryptColumn('passengers', 'passport_number');
        $this->encryptColumn('passengers', 'nationality');
        $this->encryptColumn('users', 'nationality');
        $this->encryptColumn('visa_applications', 'passport_number');
    }

    public function down(): void
    {
        // Down does NOT decrypt back — we treat the encryption as forward-only.
        // Restoring plaintext on rollback would defeat the security purpose
        // (the rollback would be visible in audit logs).
        //
        // To truly revert: restore from the pre-deploy DB backup that the
        // operations runbook requires before this migration runs.
    }

    /**
     * Encrypt all non-null plaintext values in <table>.<column>.
     * Idempotent — skips rows whose value already looks encrypted.
     */
    private function encryptColumn(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->select(['id', $column])
            ->whereNotNull($column)
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($table, $column): void {
                foreach ($rows as $row) {
                    $value = $row->{$column};
                    if ($value === null || $value === '') {
                        continue;
                    }

                    // Skip rows already encrypted. Laravel's Crypt::encryptString
                    // outputs base64-encoded JSON envelope starting with "eyJ".
                    if (is_string($value) && str_starts_with($value, 'eyJ')) {
                        continue;
                    }

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([$column => Crypt::encryptString((string) $value)]);
                }
            });
    }

    /** Driver-aware index existence check (mirrors 2026_05_20_130000). */
    private function hasIndex(string $table, string $index): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $rows = DB::select(
                "SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?",
                [$table, $index]
            );

            return ! empty($rows);
        }

        if ($driver === 'sqlite') {
            $rows = DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
                [$table, $index]
            );

            return ! empty($rows);
        }

        $rows = DB::select(
            "SELECT 1 FROM information_schema.statistics WHERE table_schema = database() AND table_name = ? AND index_name = ?",
            [$table, $index]
        );

        return ! empty($rows);
    }
};

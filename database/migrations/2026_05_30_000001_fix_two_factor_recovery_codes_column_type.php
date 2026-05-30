<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bugfix: user_two_factor.recovery_codes_encrypted was created as jsonb, but
 * UserTwoFactor casts it `encrypted:array` — which serialises to an OPAQUE
 * encrypted string (eyJpdiI6...), not JSON. Postgres rejected every insert
 * ("invalid input syntax for type json"), so 2FA setup was 100% broken on
 * prod. The feature tests missed it because they run on SQLite, which does
 * not enforce json column contents.
 *
 * Fix: widen the column to text so the encrypted blob fits. Encryption at
 * rest is preserved (the cast still encrypts); recovery codes are also
 * SHA-256 hashed by TwoFactorService before encryption, so this is safe.
 *
 * No data migration needed: every prior insert failed, so the column is empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            // jsonb → text is a valid USING cast on Postgres.
            DB::statement(
                'ALTER TABLE user_two_factor '
                .'ALTER COLUMN recovery_codes_encrypted TYPE text '
                .'USING recovery_codes_encrypted::text'
            );
        } elseif ($driver === 'sqlite') {
            // SQLite has loose typing; json/text are interchangeable. No-op.
        } else {
            // MySQL/MariaDB and others: a plain change to text.
            Schema::table('user_two_factor', function ($table): void {
                $table->text('recovery_codes_encrypted')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE user_two_factor '
                .'ALTER COLUMN recovery_codes_encrypted TYPE jsonb '
                .'USING recovery_codes_encrypted::jsonb'
            );
        }
        // sqlite/mysql down is a no-op / best-effort; not needed for rollback safety.
    }
};

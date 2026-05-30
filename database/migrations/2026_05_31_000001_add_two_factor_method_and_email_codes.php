<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor hierarchy expansion (Arshak directive 2026-05-31).
 *
 *   B2C (no role / "user") → no login-time 2FA (covered separately by
 *     email-verification at registration).
 *   Staff (super admin / operator_admin / company_admin / company_operator /
 *     agent) → 2FA enforced at every login, method = totp OR email
 *     (user picks; default email = zero setup friction).
 *   Employees of an operator/agent → 2FA toggle per-employee, the manager
 *     decides from the "Permissions" drawer.
 *
 * Schema additions:
 *
 *   users.two_factor_method enum('totp','email') nullable — user's chosen
 *     channel. NULL = no preference (auth flow then defaults to 'email'
 *     when 2FA is required).
 *   users.two_factor_required boolean default false — manager-controlled
 *     enforcement flag. Set by the registration flow for staff roles, and
 *     toggle-able from the admin "Permissions" drawer for employees.
 *
 *   user_two_factor.email_code_hash varchar(64) nullable — sha256 of the
 *     latest email-channel one-time code, cleared on consumption.
 *   user_two_factor.email_code_expires_at timestamp nullable — 10-min TTL.
 *   user_two_factor.secret_encrypted now nullable — email-method rows
 *     don't carry a TOTP secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Postgres + SQLite both accept string column with a CHECK constraint
            // for enum-like values; Laravel's `->enum()` does the right thing
            // per driver under the hood.
            $table->enum('two_factor_method', ['totp', 'email'])->nullable()->after('email_verified_at');
            $table->boolean('two_factor_required')->default(false)->after('two_factor_method');
        });

        Schema::table('user_two_factor', function (Blueprint $table): void {
            $table->string('email_code_hash', 64)->nullable()->after('recovery_codes_encrypted');
            $table->timestamp('email_code_expires_at')->nullable()->after('email_code_hash');
        });

        // Make secret_encrypted nullable — email-method users won't have a TOTP
        // secret. SQLite's table-rebuild handles this transparently; Postgres
        // needs the explicit DROP NOT NULL.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE user_two_factor ALTER COLUMN secret_encrypted DROP NOT NULL');
        } else {
            // SQLite (test env): use Doctrine via Laravel's change()
            Schema::table('user_two_factor', function (Blueprint $table): void {
                $table->text('secret_encrypted')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_method', 'two_factor_required']);
        });

        Schema::table('user_two_factor', function (Blueprint $table): void {
            $table->dropColumn(['email_code_hash', 'email_code_expires_at']);
        });

        // Revert secret_encrypted NOT NULL.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            // Existing rows might be NULL (email-only users). Set to empty
            // string before re-applying NOT NULL so the migration is reversible
            // in dev without losing rows.
            DB::statement("UPDATE user_two_factor SET secret_encrypted = '' WHERE secret_encrypted IS NULL");
            DB::statement('ALTER TABLE user_two_factor ALTER COLUMN secret_encrypted SET NOT NULL');
        } else {
            Schema::table('user_two_factor', function (Blueprint $table): void {
                $table->text('secret_encrypted')->nullable(false)->change();
            });
        }
    }
};

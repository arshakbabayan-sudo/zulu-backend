<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 10.06 §8c — seller (operator/agent) loyalty accounts + points expiry.
 *
 * loyalty_accounts gains account_type ('customer'|'seller') + a nullable
 * company_id so a seller COMPANY can own an account (user_id is then NULL).
 * loyalty_transactions gains expires_at (the earn lot's expiry) + expired_at
 * (set by the expiry sweep so each lot is processed exactly once).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('loyalty_accounts', function (Blueprint $table): void {
            $table->string('account_type')->default('customer')->after('id'); // customer | seller
            $table->unsignedBigInteger('company_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('loyalty_accounts', function (Blueprint $table): void {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->index('account_type');
            // One loyalty account per seller company. (Postgres/SQLite treat
            // NULL company_id rows — the customer accounts — as distinct, so this
            // unique does not clash with the existing per-user accounts.)
            $table->unique('company_id');
        });

        Schema::table('loyalty_transactions', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('happened_at');
            $table->timestamp('expired_at')->nullable()->after('expires_at');
            $table->index('expires_at');
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE loyalty_accounts ADD CONSTRAINT loyalty_accounts_account_type_check CHECK (account_type IN ('customer','seller'))");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE loyalty_accounts DROP CONSTRAINT IF EXISTS loyalty_accounts_account_type_check');
        }

        Schema::table('loyalty_transactions', function (Blueprint $table): void {
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['expires_at', 'expired_at']);
        });

        Schema::table('loyalty_accounts', function (Blueprint $table): void {
            $table->dropUnique(['company_id']);
            $table->dropIndex(['account_type']);
            $table->dropForeign(['company_id']);
            $table->dropColumn(['account_type', 'company_id']);
        });
    }
};

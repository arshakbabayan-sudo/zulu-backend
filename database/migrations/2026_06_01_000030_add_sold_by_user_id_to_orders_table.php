<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee sales attribution (2026-06-01).
 *
 * `sold_by_user_id` records WHICH employee made a sale, so the CRM "Team"
 * leaderboard + payroll can roll revenue up per person. Nullable + additive:
 * existing orders stay unattributed (null), nothing else changes. The column
 * is set going forward by whichever flow an operator uses to record a sale
 * (admin booking creation, deal→won conversion, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('sold_by_user_id')->nullable()->after('agent_company_id')
                ->constrained('users')->nullOnDelete();
            $table->index('sold_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sold_by_user_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-operator price markup, super-admin set, two tiers.
 *
 * Both columns are NULLABLE — NULL means "no per-operator value, fall back
 * to the global b2c_markup_percent (today's 15%)". This keeps the migration
 * safe on a populated table: no backfill, every existing operator keeps the
 * exact behaviour it has today.
 *
 *  - agent_markup_percent    → applied to seller_b2b (agent, netto) buyers
 *  - customer_markup_percent → applied to client/guest (brutto) buyers AND
 *                              to the public catalog display
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->decimal('agent_markup_percent', 5, 2)->nullable()->after('packages_trusted_at');
            $table->decimal('customer_markup_percent', 5, 2)->nullable()->after('agent_markup_percent');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['agent_markup_percent', 'customer_markup_percent']);
        });
    }
};

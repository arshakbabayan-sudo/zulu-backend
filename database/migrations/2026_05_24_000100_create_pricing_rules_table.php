<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 / Step C.1 — `pricing_rules` table.
 *
 * Unified markup + commission rules. 4-level priority resolution:
 *   partnership > operator > category > global
 *
 * Replaces both `platform_settings.b2c_markup_percent` (single global
 * MVP markup) and `operator_agent_commission` (Phase 6B parallel table)
 * for NEW bookings. Both old structures stay in place for legacy data
 * — order_pricing_snapshots.engine flag distinguishes which produced
 * the historical price.
 *
 * Idempotent + reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pricing_rules')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('pricing_rules', function (Blueprint $table) use ($driver): void {
            $table->uuid('id')->primary();

            // 4-level priority resolver
            $table->string('scope_type', 16); // partnership|operator|category|global
            $table->foreignId('operator_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('companies')->nullOnDelete();

            // Optional filters (any combination)
            $table->string('service_category', 32)->nullable(); // hotel|flight|transfer|car|excursion|package|visa
            $table->foreignId('destination_id')->nullable()->constrained('locations')->nullOnDelete();

            // Markup config
            $table->string('markup_type', 16); // percentage|fixed
            $table->decimal('markup_value', 12, 4);

            // Optional sell-price bounds
            $table->decimal('min_sell_amount', 14, 4)->nullable();
            $table->decimal('max_sell_amount', 14, 4)->nullable();

            $table->char('currency', 3);

            // Time-window + priority
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->integer('priority')->default(100); // higher wins within same scope+match
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['scope_type', 'operator_id', 'agent_id', 'service_category', 'is_active'],
                'pricing_rules_resolver_idx'
            );
            $table->index(['effective_from', 'effective_until'], 'pricing_rules_effective_idx');
            $table->index('currency', 'pricing_rules_currency_idx');
        });

        if ($driver === 'pgsql') {
            // Enforce enum-like constraints at DB level. SQLite skip — the
            // app validates these via the resolver's allow-list.
            DB::statement(
                "ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_scope_type_chk
                CHECK (scope_type IN ('partnership','operator','category','global'))"
            );
            DB::statement(
                "ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_markup_type_chk
                CHECK (markup_type IN ('percentage','fixed'))"
            );
            // Scope-shape invariants:
            //   partnership requires both operator_id + agent_id
            //   operator requires operator_id (agent_id null)
            //   category requires service_category, no operator/agent
            //   global allows no operator/agent/category narrowing
            DB::statement(
                "ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_scope_shape_chk
                CHECK (
                    (scope_type = 'partnership' AND operator_id IS NOT NULL AND agent_id IS NOT NULL) OR
                    (scope_type = 'operator'    AND operator_id IS NOT NULL AND agent_id IS NULL) OR
                    (scope_type = 'category'    AND operator_id IS NULL AND agent_id IS NULL AND service_category IS NOT NULL) OR
                    (scope_type = 'global'      AND operator_id IS NULL AND agent_id IS NULL)
                )"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasTable('pricing_rules')) {
            DB::statement('ALTER TABLE pricing_rules DROP CONSTRAINT IF EXISTS pricing_rules_scope_type_chk');
            DB::statement('ALTER TABLE pricing_rules DROP CONSTRAINT IF EXISTS pricing_rules_markup_type_chk');
            DB::statement('ALTER TABLE pricing_rules DROP CONSTRAINT IF EXISTS pricing_rules_scope_shape_chk');
        }

        Schema::dropIfExists('pricing_rules');
    }
};

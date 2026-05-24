<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 / Step C.1 — `money_flow_terms` table.
 *
 * Per spec: three collection models for agent-mediated bookings.
 *   - Model A (zulu_collects): customer/agent pays Zulu, Zulu remits to
 *     operator T+N days later. Default.
 *   - Model B (operator_collects): customer/agent pays operator directly,
 *     Zulu invoices operator weekly/monthly.
 *   - Model C (agent_collects): customer pays agent, agent forwards
 *     net+markup to operator/Zulu.
 *
 * Customer bookings (no agent involved) always use Model A regardless of
 * configured rows. MoneyFlowResolver handles the customer-bookings shortcut.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('money_flow_terms')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('money_flow_terms', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('scope_type', 16); // partnership|operator|global
            $table->foreignId('operator_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->string('collection_model', 32); // zulu_collects|operator_collects|agent_collects

            // For zulu_collects: T+N days remittance window
            $table->unsignedInteger('remittance_days')->nullable();
            // For operator_collects: how often Zulu invoices operator
            $table->string('invoicing_period', 16)->nullable(); // weekly|monthly

            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_until')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['scope_type', 'operator_id', 'agent_id', 'is_active'],
                'money_flow_terms_resolver_idx'
            );
            $table->index(['effective_from', 'effective_until'], 'money_flow_terms_effective_idx');
        });

        if ($driver === 'pgsql') {
            DB::statement(
                "ALTER TABLE money_flow_terms ADD CONSTRAINT money_flow_terms_scope_type_chk
                CHECK (scope_type IN ('partnership','operator','global'))"
            );
            DB::statement(
                "ALTER TABLE money_flow_terms ADD CONSTRAINT money_flow_terms_collection_model_chk
                CHECK (collection_model IN ('zulu_collects','operator_collects','agent_collects'))"
            );
            DB::statement(
                "ALTER TABLE money_flow_terms ADD CONSTRAINT money_flow_terms_invoicing_period_chk
                CHECK (invoicing_period IS NULL OR invoicing_period IN ('weekly','monthly'))"
            );
            DB::statement(
                "ALTER TABLE money_flow_terms ADD CONSTRAINT money_flow_terms_scope_shape_chk
                CHECK (
                    (scope_type = 'partnership' AND operator_id IS NOT NULL AND agent_id IS NOT NULL) OR
                    (scope_type = 'operator'    AND operator_id IS NOT NULL AND agent_id IS NULL) OR
                    (scope_type = 'global'      AND operator_id IS NULL AND agent_id IS NULL)
                )"
            );
            // For zulu_collects: remittance_days REQUIRED. For operator_collects: invoicing_period REQUIRED.
            DB::statement(
                "ALTER TABLE money_flow_terms ADD CONSTRAINT money_flow_terms_model_fields_chk
                CHECK (
                    (collection_model = 'zulu_collects'     AND remittance_days IS NOT NULL) OR
                    (collection_model = 'operator_collects' AND invoicing_period IS NOT NULL) OR
                    (collection_model = 'agent_collects')
                )"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasTable('money_flow_terms')) {
            foreach ([
                'money_flow_terms_scope_type_chk',
                'money_flow_terms_collection_model_chk',
                'money_flow_terms_invoicing_period_chk',
                'money_flow_terms_scope_shape_chk',
                'money_flow_terms_model_fields_chk',
            ] as $constraint) {
                DB::statement("ALTER TABLE money_flow_terms DROP CONSTRAINT IF EXISTS {$constraint}");
            }
        }
        Schema::dropIfExists('money_flow_terms');
    }
};

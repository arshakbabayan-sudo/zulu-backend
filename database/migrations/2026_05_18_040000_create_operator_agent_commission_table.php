<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6B — operator-to-agent commission configuration.
 *
 * Configures how an operator's revenue is split with the agent who referred
 * a booking. Per the roadmap the formula stays FLEXIBLE — operator picks the
 * calculation base (gross / post_platform_fee / custom %), a default
 * percentage for all agents, and zero or more per-agent overrides.
 *
 * `agent_company_id = NULL` rows are the default for the operator. Non-null
 * rows are per-agent overrides. The two partial unique indexes enforce
 * exactly one default per operator and at most one override per pair without
 * needing a sentinel value.
 *
 * Booking-time integration (FinanceService extension + bookings.agent_id
 * column) lands in Phase 6B part 2; this commit just lands the config table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_agent_commission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('agent_company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            // 'gross' | 'post_platform_fee' | 'custom'
            $table->string('calculation_base', 32)->default('gross');
            // Used when calculation_base = 'custom' — caller picks the base percentage of gross.
            $table->decimal('custom_base_percentage', 6, 3)->nullable();
            // Commission share paid to the agent (or per-agent override when set).
            $table->decimal('default_percentage', 6, 3)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('operator_company_id');
        });

        // Partial unique indexes: one default per operator, one override per
        // (operator, agent) pair. PostgreSQL syntax — adjust if other drivers
        // ever become a target.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX operator_agent_commission_default_uq
                ON operator_agent_commission(operator_company_id)
                WHERE agent_company_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX operator_agent_commission_override_uq
                ON operator_agent_commission(operator_company_id, agent_company_id)
                WHERE agent_company_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_agent_commission');
    }
};

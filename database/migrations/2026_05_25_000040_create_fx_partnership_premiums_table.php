<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase Զ.16 / Item 18 — per-partnership FX premium.
 *
 * Some partnerships need a custom markup on top of the platform's FX
 * rate (e.g. operator wants +2.5% on USD→AMD when selling through a
 * specific agent). This table lets super-admins pin a premium per
 * (operator, agent, currency-pair) without touching the global FX rate
 * row.
 *
 * Applied by FxConverter::convertWithPartnership(operatorId, agentId,
 * amount, src, tgt) — adds premium_percent to the base rate before
 * computing the converted amount.
 *
 * Scope:
 *   - operator_id required
 *   - agent_id nullable (NULL means "any agent of this operator")
 *   - source_currency + target_currency 3-char codes
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_partnership_premiums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('source_currency', 3);
            $table->string('target_currency', 3);
            $table->decimal('premium_percent', 5, 2);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['operator_id', 'agent_id'], 'fx_pp_pair_idx');
            $table->index(['source_currency', 'target_currency'], 'fx_pp_currency_idx');
            // Same partnership + pair can only have ONE active row.
            $table->unique(
                ['operator_id', 'agent_id', 'source_currency', 'target_currency'],
                'fx_pp_unique_active'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_partnership_premiums');
    }
};

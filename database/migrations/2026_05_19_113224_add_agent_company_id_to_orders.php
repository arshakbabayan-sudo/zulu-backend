<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6B Part 2 — orders.agent_company_id.
 *
 * Records which agent company referred a booking, so FinanceService can look
 * up the matching OperatorAgentCommission row at confirm-time and create a
 * second SupplierEntitlement for the agent on top of the operator's row.
 *
 * Nullable: not every order is agent-referred (direct B2C bookings have no
 * agent). When null, only the operator entitlement is created — same as the
 * pre-Phase-6B behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('agent_company_id')->nullable()->after('company_id');
            $table->foreign('agent_company_id')->references('id')->on('companies')->nullOnDelete();
            $table->index('agent_company_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['agent_company_id']);
            $table->dropIndex(['agent_company_id']);
            $table->dropColumn('agent_company_id');
        });
    }
};

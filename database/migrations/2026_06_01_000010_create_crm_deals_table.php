<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM deals — the sales-opportunity pipeline (New → Qualified → Proposal →
 * Negotiation → Won/Lost). Mirrors docs/admin_designe/new-admin-designe/
 * files/crm.html "Deals" kanban.
 *
 * Tenancy: company_id scopes a deal to the operator/agent company that owns
 * it (super-admin sees all). owner_user_id is the employee responsible — this
 * is the hook the "Team" sales-performance view rolls up on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            // Who the deal is with (an existing platform user — B2C customer or
            // an agent-company contact). Nullable so a deal can be drafted first.
            $table->foreignId('customer_user_id')->nullable()->constrained('users')->nullOnDelete();
            // The employee who owns/works the deal (sales attribution).
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->decimal('value_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('service_type')->nullable(); // hotel/flight/package/tour/transfer
            $table->string('stage')->default('new');    // new|qualified|proposal|negotiation|won|lost
            $table->unsignedTinyInteger('probability')->default(0); // 0-100
            $table->string('source')->nullable();        // website/referral/social/partner/walk_in
            $table->date('expected_close_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'stage']);
            $table->index('owner_user_id');
            $table->index('customer_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_deals');
    }
};

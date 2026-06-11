<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Leads + Segments (roadmap_10.06.2026 §4 — the two "coming soon" CRM
 * tabs). Design reference: docs/admin_designe/new-admin-designe/crm.html
 * (Leads table + Segments card grid) + crm_INTEGRATION.md endpoint contract.
 *
 * crm_leads — inbound enquiries that are not yet deals. A lead is qualified
 * through new → contacted → qualified | unqualified and can be CONVERTED
 * into a crm_deals row (status becomes 'converted', converted_deal_id links
 * the resulting deal). Tenancy mirrors crm_deals: company_id scopes to the
 * owning company, owner_user_id is the responsible employee (Layer-B row
 * scope key).
 *
 * crm_segments — saved customer groups for targeting. type=dynamic stores a
 * rules json evaluated live against the company's buyers (orders-derived,
 * same definition as CRM → Customers); type=static keeps an explicit member
 * list in crm_segment_members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            // The employee responsible for working the lead (sales attribution
            // + Layer-B row scoping, same as crm_deals.owner_user_id).
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            // Set ⇒ this is a B2B enquiry from a partner company (mock shows
            // "B2B · partner@touroperator.co" sub-line).
            $table->string('b2b_company')->nullable();
            $table->string('source')->nullable();   // website|referral|social|walk_in|partner
            $table->string('interest')->nullable(); // hotel|flight|package|tour|transfer
            $table->string('status')->default('new'); // new|contacted|qualified|unqualified|converted
            $table->text('notes')->nullable();
            // Filled by POST crm/leads/{lead}/convert — the deal this lead became.
            $table->foreignId('converted_deal_id')->nullable()->constrained('crm_deals')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index('owner_user_id');
        });

        Schema::create('crm_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('type')->default('dynamic'); // dynamic|static
            // Tabler icon name shown on the segment card (ti-crown, ti-repeat…).
            $table->string('icon', 50)->nullable();
            // Dynamic-segment rules, AND-combined (all optional):
            // {min_bookings, min_total_spent, inactive_months, active_months}.
            $table->json('rules')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
        });

        Schema::create('crm_segment_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('segment_id')->constrained('crm_segments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['segment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_segment_members');
        Schema::dropIfExists('crm_segments');
        Schema::dropIfExists('crm_leads');
    }
};

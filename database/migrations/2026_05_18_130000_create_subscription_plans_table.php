<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.11 — subscription plans + per-company subscription rows.
 *
 * Two tables: catalog of available plans (super-admin defined) and the
 * row that records which plan a company is currently on. Stripe / ArCa
 * auto-renew wiring is parked per memory `feedback_no_payment_integration_yet.md`
 * — for now the period_ends_at column is manually managed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique(); // free | pro | enterprise | etc.
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->decimal('monthly_price', 10, 2)->default(0);
            $table->decimal('annual_price', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            // JSON-encoded list of feature keys this plan unlocks.
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('company_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            // active | cancelled | past_due | trial
            $table->string('status', 32)->default('active');
            // monthly | annual
            $table->string('billing_period', 16)->default('monthly');
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('company_id'); // one current subscription per company
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};

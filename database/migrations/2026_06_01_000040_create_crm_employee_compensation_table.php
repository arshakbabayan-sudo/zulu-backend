<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee compensation config (2026-06-01).
 *
 * Arshak's requirement: operators/agents must be able to pay each employee
 * flexibly — fixed salary + commission %, fixed only, or commission % only.
 * One row per (employee, company); the CRM Team view computes monthly pay as
 *   base_amount + (commission_percent/100 × attributed_revenue_in_currency).
 *
 * `model` is the chosen scheme; the calculator ignores the irrelevant field
 * (e.g. percent-only zeroes the base) so the stored numbers stay honest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_employee_compensation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('model')->default('fixed_plus_percent'); // fixed|percent|fixed_plus_percent
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('commission_percent', 6, 3)->default(0); // e.g. 5.000 = 5%
            $table->string('currency', 3)->default('USD');
            $table->text('notes')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_employee_compensation');
    }
};

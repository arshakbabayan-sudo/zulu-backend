<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company CRM settings (2026-06-01).
 *
 * Arshak's requirement: the manager must control HOW an employee's sales are
 * counted — sometimes a booking counts before it's confirmed, sometimes only
 * after. Rather than hard-code which order statuses count, store it per company
 * as a checkbox-driven setting. One JSON blob per company keeps it extensible
 * (future CRM options live in the same row).
 *
 * Known keys today:
 *   sales_count_statuses : string[]  — order statuses that count toward an
 *                                      employee's sales (default paid+confirmed)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_company_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->json('settings')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_company_settings');
    }
};

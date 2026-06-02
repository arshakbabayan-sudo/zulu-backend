<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC blueprint Phase 4 — ՍԱ-staff (ZULU platform staff) data assignments.
 *
 * A super-admin sees ALL companies. A platform_admin (genuine ZULU staff, not
 * an operator) sees ONLY the companies a super-admin assigns them — by direct
 * company OR by country (e.g. assigned country "Armenia" → every company in
 * Armenia). Resolved by AdminAccessService::assignedCompanyIds(), which feeds
 * visibleCompanyIds() for the platform-staff branch.
 *
 * Each row carries EITHER company_id OR country (enforced at the endpoint, not
 * the DB — a partial unique with NULLs is awkward in Postgres). No rows for a
 * staffer = they see nothing (fail-closed; super-admin must assign them).
 *
 * NOTE on country: companies.country is free-text and inconsistent in prod
 * ("Армения" vs "Armenia"), so country matching is case-insensitive/trimmed
 * and best-effort — direct company_id assignment is the precise mechanism.
 * A separate country-normalisation cleanup would make country assignment fully
 * reliable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_staff_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('country')->nullable();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('user_id');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_staff_scopes');
    }
};

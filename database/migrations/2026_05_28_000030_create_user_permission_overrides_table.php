<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase Գ.6 / Bucket D.4 — per-employee permission overrides.
 *
 * The existing RBAC model grants permissions through a user's ROLE in a
 * company (user_company.role_id → role_permissions). That is the baseline.
 * This table layers FLEXIBLE per-employee adjustments on top, as decided by
 * the product owner: an operator/agent manager can tick a checkbox per
 * action per employee.
 *
 *   effect = 'allow'  → grant this permission even if the role does not.
 *   effect = 'deny'   → revoke this permission even if the role grants it.
 *
 * Effective permission for (user, company, permission) =
 *     (role grants it OR an allow-row exists) AND NOT a deny-row exists.
 *
 * No row = pure role behaviour (zero regression for existing tenants).
 * granted_by_user_id records who last set the override (audit trail).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('effect', 10)->default('allow'); // 'allow' | 'deny'
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'company_id', 'permission_id'], 'upo_user_company_permission_unique');
            $table->index(['user_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};

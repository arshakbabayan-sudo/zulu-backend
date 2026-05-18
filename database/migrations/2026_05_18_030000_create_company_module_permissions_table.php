<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6A — company-level module permissions.
 *
 * Super admin grants/denies access to admin-panel modules per company. The
 * sidebar reads these rows when computing which tabs to show for the
 * operator/agent users of that company.
 *
 * Default behaviour (no row exists): allowed. Only explicit `is_allowed=false`
 * rows hide a module. This means existing companies see everything as before
 * and a super admin only has to flip the modules they want to deny.
 *
 * Distinct from `company_seller_permissions` (per-service inventory gating)
 * and `company_country_permissions` (per-country sales gating). Module gating
 * controls broader admin sections — bookings, finance, loyalty, contracts,
 * vouchers, etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_module_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // Free-form key matching AdminNavTab.moduleKey on the frontend.
            // Stored as string so adding modules requires no migration.
            $table->string('module_key', 64);
            $table->boolean('is_allowed')->default(true);
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'module_key']);
            $table->index('company_id');
            $table->index(['is_allowed', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_module_permissions');
    }
};

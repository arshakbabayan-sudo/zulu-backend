<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.2 — admin-initiated archive of a company.
 *
 * Soft-delete-style hiding via a custom `archived_at` column (NOT Laravel's
 * built-in `deleted_at` SoftDeletes — we want the marker to be clearly
 * "archived" rather than "deleted", and we want it to remain queryable
 * without `withTrashed()`).
 *
 * Linked rows (orders, contracts, inventory) stay intact — companies are
 * archived for visibility, not for data destruction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('updated_at')->index();
            $table->unsignedBigInteger('archived_by_user_id')->nullable()->after('archived_at');
            $table->string('archived_reason', 500)->nullable()->after('archived_by_user_id');

            $table->foreign('archived_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropForeign(['archived_by_user_id']);
            $table->dropColumn(['archived_at', 'archived_by_user_id', 'archived_reason']);
        });
    }
};

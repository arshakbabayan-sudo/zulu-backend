<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review-gate publishing workflow (decision logged in
 * project_locations_overhaul.md, "Option 2"):
 *
 *   draft → operator submits for review → pending_review →
 *   super admin reviews → approves → published
 *                       → rejects  → rejected (with reason)
 *
 * Adds the audit fields the queue UI needs to display "who reviewed
 * this and when, and (if rejected) why".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            if (! Schema::hasColumn('offers', 'submitted_for_review_at')) {
                $table->timestamp('submitted_for_review_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('offers', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('submitted_for_review_at');
            }
            if (! Schema::hasColumn('offers', 'reviewed_by')) {
                $table->foreignId('reviewed_by')
                    ->nullable()
                    ->after('reviewed_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('offers', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('reviewed_by');
            }

            // status index already present from prior migrations (offers_status_index)
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            if (Schema::hasColumn('offers', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }
            if (Schema::hasColumn('offers', 'reviewed_by')) {
                $table->dropConstrainedForeignId('reviewed_by');
            }
            if (Schema::hasColumn('offers', 'reviewed_at')) {
                $table->dropColumn('reviewed_at');
            }
            if (Schema::hasColumn('offers', 'submitted_for_review_at')) {
                $table->dropColumn('submitted_for_review_at');
            }
        });
    }
};

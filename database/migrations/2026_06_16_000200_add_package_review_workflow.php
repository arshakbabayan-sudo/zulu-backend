<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §10 — package multi-level approval. Business rule (Arshak 2026-06-16): a
 * company's FIRST package must be approved by a ZULU admin; once approved the
 * company is flagged trusted and publishes subsequent packages freely.
 *
 * Adds the review trail to `packages` + a per-company `packages_trusted_at`
 * flag, then BACKFILLS established partners (any company that already has a
 * package which left 'draft') so the new first-package gate never blocks a
 * company whose packages were already live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('packages', 'submitted_for_review_at')) {
                $table->timestamp('submitted_for_review_at')->nullable();
            }
            if (! Schema::hasColumn('packages', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }
            if (! Schema::hasColumn('packages', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('packages', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
        });

        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'packages_trusted_at')) {
                $table->timestamp('packages_trusted_at')->nullable();
            }
        });

        // Established partners (already have a non-draft package) stay trusted.
        DB::table('companies')
            ->whereNull('packages_trusted_at')
            ->whereExists(function ($q): void {
                $q->select(DB::raw('1'))
                    ->from('packages')
                    ->whereColumn('packages.company_id', 'companies.id')
                    ->where('packages.status', '!=', 'draft');
            })
            ->update(['packages_trusted_at' => Carbon::now()]);
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            if (Schema::hasColumn('packages', 'reviewed_by')) {
                $table->dropConstrainedForeignId('reviewed_by');
            }
            $drop = array_values(array_filter(
                ['submitted_for_review_at', 'reviewed_at', 'rejection_reason'],
                fn (string $c): bool => Schema::hasColumn('packages', $c)
            ));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        Schema::table('companies', function (Blueprint $table): void {
            if (Schema::hasColumn('companies', 'packages_trusted_at')) {
                $table->dropColumn('packages_trusted_at');
            }
        });
    }
};

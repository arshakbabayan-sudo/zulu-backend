<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backend audit C4 (§5) — over-refund / double-refund hardening.
 *
 * Adds a cumulative `refunded_amount` to payments so the refund path can cap
 * total refunds at the paid amount (no partial-refund-past-100%) and so a
 * concurrency-claimed refund increments a single source of truth.
 *
 * Safety on a non-empty table:
 *  - new column is nullable with a server default of 0 → every existing row is
 *    valid immediately, no rewrite of NULLs required, no NOT NULL backfill race.
 *  - cheap backfill: any payment ALREADY in the terminal REFUNDED status is, by
 *    the old full-refund semantics, fully refunded, so we set its
 *    refunded_amount = amount. Partially-refunded-but-still-PAID rows had no
 *    tracking before this migration, so they correctly stay at 0 (we cannot
 *    reconstruct history) and continue to rely on status as before.
 *  - fully reversible: down() drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        if (! Schema::hasColumn('payments', 'refunded_amount')) {
            Schema::table('payments', function (Blueprint $table): void {
                // Nullable + default 0 keeps the change safe on a populated table:
                // existing rows get 0 with no backfill scan needed for validity.
                $table->decimal('refunded_amount', 12, 2)->nullable()->default(0)->after('amount');
            });
        }

        // Cheap, deterministic backfill: terminal-REFUNDED payments were fully
        // refunded under the old semantics, so their cumulative = their amount.
        DB::table('payments')
            ->where('status', 'refunded')
            ->update(['refunded_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'refunded_amount')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropColumn('refunded_amount');
            });
        }
    }
};

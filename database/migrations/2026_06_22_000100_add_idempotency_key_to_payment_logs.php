<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backend audit C3 — make the payment webhook idempotent at the DB level.
 *
 * Today payment_logs has only PLAIN indexes, so two concurrent / retried
 * deliveries of the SAME gateway event (Stripe resends aggressively) can both
 * pass the in-memory `status !== paid` guard in
 * PaymentWebhookController::processEvent and both run markPaid → double
 * PaymentReceived, double invoice/order cascade, double loyalty accrual.
 *
 * The fix records each processed event under a stable, gateway-scoped
 * idempotency token (the gateway's own EVENT id when it has one — Stripe's
 * evt_…, Idram's EDP_TRANS_ID — else a composite reference:event token) in a
 * NEW nullable column `gateway_event_id`, and enforces uniqueness on it with a
 * PARTIAL unique index. The controller INSERTs that row FIRST inside the
 * transaction; the SECOND delivery's insert fails atomically at the DB
 * (23505 pgsql / 23000 sqlite) and is treated as "already processed".
 *
 * SAFETY on a non-empty prod table:
 *   - The column is BRAND NEW, so every existing row is gateway_event_id = NULL.
 *     No historical dedupe is needed and the partial index builds cleanly.
 *   - The index is PARTIAL (WHERE gateway_event_id IS NOT NULL), so the many
 *     existing non-webhook inserts that never set the key (intent.created,
 *     intent.retrieved, refund.created, refund.manual_required, …) keep writing
 *     NULL and are EXCLUDED from the uniqueness check — they can't collide and
 *     can't break.
 *   - Both Postgres (prod) and SQLite (tests) support partial unique indexes
 *     with a WHERE predicate — proven by the existing precedent
 *     2026_06_13_000800_chat_customer_thread_unique_index.php.
 */
return new class extends Migration
{
    private const INDEX_NAME = 'payment_logs_gateway_event_unique';

    public function up(): void
    {
        // Step A — add the nullable idempotency-key column. nullable() is
        // load-bearing: it does NOT break the many existing/ongoing inserts that
        // never set the key (they stay NULL and the partial index ignores them).
        if (! Schema::hasColumn('payment_logs', 'gateway_event_id')) {
            Schema::table('payment_logs', function (Blueprint $table): void {
                $table->string('gateway_event_id', 255)->nullable()->after('gateway_reference');
            });
        }

        // Defensive dedupe — the column is brand new and all-NULL so this is a
        // no-op today, but it keeps the migration safe to re-run / safe if a
        // backfill ever populated the column before the index existed. Keep the
        // EARLIEST row (lowest id) per (gateway, gateway_event_id) and delete the
        // rest so the unique index can always build. NULL keys are skipped (a
        // NULL group never collides under the partial index).
        $dupes = DB::table('payment_logs')
            ->select('gateway', 'gateway_event_id', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('gateway_event_id')
            ->groupBy('gateway', 'gateway_event_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $dupe) {
            DB::table('payment_logs')
                ->where('gateway', $dupe->gateway)
                ->where('gateway_event_id', $dupe->gateway_event_id)
                ->where('id', '!=', $dupe->keep_id)
                ->delete();
        }

        // Step B — enforce uniqueness on NON-NULL keys only via a PARTIAL unique
        // index. The explicit WHERE … IS NOT NULL guarantees NULL-key rows never
        // collide on either engine.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX_NAME
            .' ON payment_logs (gateway, gateway_event_id) WHERE gateway_event_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);

        if (Schema::hasColumn('payment_logs', 'gateway_event_id')) {
            Schema::table('payment_logs', function (Blueprint $table): void {
                $table->dropColumn('gateway_event_id');
            });
        }
    }
};

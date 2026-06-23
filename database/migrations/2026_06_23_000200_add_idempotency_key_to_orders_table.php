<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 8 (roadmap §duplicate-order guard) — order-creation idempotency.
 *
 * A double-submit (double-click, impatient retry, or a network retry that lost
 * the first response) must NOT create two Orders + two charges. The frontend
 * now sends a stable per-attempt `Idempotency-Key` header; the server records
 * it on the created Order and, on a repeat with the same (user, key), returns
 * the existing Order instead of creating a duplicate.
 *
 * Column: nullable `idempotency_key` (string, 64). NULLs are distinct under
 * both Postgres and SQLite, so existing / keyless orders are unaffected and no
 * backfill is needed — additive + safe on a populated table.
 *
 * Index: UNIQUE on (user_id, idempotency_key). Scoping the uniqueness per user
 * means two different users that happen to mint the same key still get their
 * own orders, and the DB itself becomes the race-safe guard: two simultaneous
 * requests with the same (user, key) — only one INSERT wins, the loser catches
 * the unique violation and re-fetches the winner's order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('order_number');
            $table->unique(['user_id', 'idempotency_key'], 'orders_user_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_user_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};

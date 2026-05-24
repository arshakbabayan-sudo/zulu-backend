<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 / Step C.1 — `exchange_rates` table.
 *
 * FX foundation for the multi-currency marketplace. Daily refresh via
 * `fx:refresh` artisan job (C.4) pulls from CBA (primary) and ECB
 * (backup). exchangerate-api as last-resort fallback. Manual rows
 * (`source = 'manual'` or `'partner_override'`) win over all auto-pulled.
 *
 * At booking time, the order's FX rate is captured in
 * order_pricing_snapshots.snapshot_json so customer-paid amounts are
 * locked regardless of rate changes thereafter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exchange_rates')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->char('source_currency', 3);
            $table->char('target_currency', 3);
            $table->decimal('rate', 14, 6); // 1 USD = 387.250000 AMD

            // cba|ecb|exchangerate_api|manual|partner_override
            $table->string('source', 32);

            $table->timestamp('fetched_at');
            $table->boolean('is_active')->default(true);

            $table->timestamp('created_at')->useCurrent();
            // No updated_at — when a new rate arrives, the new row is
            // is_active=true and the previous row is flipped to false.

            $table->index(
                ['source_currency', 'target_currency', 'is_active'],
                'exchange_rates_lookup_idx'
            );
            $table->index('source', 'exchange_rates_source_idx');
            $table->index('fetched_at', 'exchange_rates_fetched_idx');
        });

        if ($driver === 'pgsql') {
            DB::statement(
                "ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_source_chk
                CHECK (source IN ('cba','ecb','exchangerate_api','manual','partner_override'))"
            );

            // At most one is_active=true row per (source_currency, target_currency, source)
            // tuple. Partial unique constraint via index.
            DB::statement(
                'CREATE UNIQUE INDEX exchange_rates_active_uq
                ON exchange_rates (source_currency, target_currency, source)
                WHERE is_active = true'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasTable('exchange_rates')) {
            DB::statement('DROP INDEX IF EXISTS exchange_rates_active_uq');
            DB::statement('ALTER TABLE exchange_rates DROP CONSTRAINT IF EXISTS exchange_rates_source_chk');
        }

        Schema::dropIfExists('exchange_rates');
    }
};

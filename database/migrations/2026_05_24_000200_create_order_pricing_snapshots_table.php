<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 / Step C.1 — `order_pricing_snapshots` table.
 *
 * Immutable per-order audit of how each line price was computed.
 * If a pricing_rule mutates after the booking, the order keeps its
 * original price — critical for finance auditability.
 *
 * Per audit doc §8.4: snapshots key off order_id, NOT a polymorphic
 * morphTo, because both Booking and Package flows produce an Order
 * record (via OrderService::create()).
 *
 * One row per order_item.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_pricing_snapshots')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('order_pricing_snapshots', function (Blueprint $table) use ($driver): void {
            $table->bigIncrements('id');

            // Each order item has one snapshot. order_items.id is uuid.
            $table->uuid('order_id'); // foreign key — orders.id (uuid)
            $table->uuid('order_item_id')->nullable(); // foreign key — order_items.id; nullable for legacy
            $table->unsignedBigInteger('offer_id');

            $table->decimal('supplier_net', 14, 4);
            $table->decimal('zulu_markup_value', 14, 4);
            $table->string('zulu_markup_type', 16); // percentage|fixed|legacy

            $table->decimal('agent_net', 14, 4)->nullable();
            $table->decimal('customer_paid', 14, 4);
            $table->decimal('agent_margin', 14, 4)->nullable();

            $table->uuid('rule_id_applied')->nullable(); // foreign key — pricing_rules.id
            $table->char('currency', 3);

            // Full resolver output for audit + report regeneration.
            $driver === 'pgsql'
                ? $table->jsonb('snapshot_json')
                : $table->json('snapshot_json');

            $table->timestamp('created_at')->useCurrent();
            // No updated_at — snapshots are immutable.

            $table->index('order_id', 'order_pricing_snapshots_order_idx');
            $table->index('order_item_id', 'order_pricing_snapshots_item_idx');
            $table->index('offer_id', 'order_pricing_snapshots_offer_idx');
            $table->index('rule_id_applied', 'order_pricing_snapshots_rule_idx');
        });

        if ($driver === 'pgsql') {
            DB::statement(
                "ALTER TABLE order_pricing_snapshots ADD CONSTRAINT order_pricing_snapshots_markup_type_chk
                CHECK (zulu_markup_type IN ('percentage','fixed','legacy'))"
            );
            // FKs separately so a missing parent (legacy row) doesn't block the table create.
            DB::statement(
                'ALTER TABLE order_pricing_snapshots
                ADD CONSTRAINT order_pricing_snapshots_order_id_fk
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE'
            );
            DB::statement(
                'ALTER TABLE order_pricing_snapshots
                ADD CONSTRAINT order_pricing_snapshots_item_id_fk
                FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE'
            );
            DB::statement(
                'ALTER TABLE order_pricing_snapshots
                ADD CONSTRAINT order_pricing_snapshots_rule_id_fk
                FOREIGN KEY (rule_id_applied) REFERENCES pricing_rules(id) ON DELETE SET NULL'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasTable('order_pricing_snapshots')) {
            foreach (['order_pricing_snapshots_markup_type_chk',
                'order_pricing_snapshots_order_id_fk',
                'order_pricing_snapshots_item_id_fk',
                'order_pricing_snapshots_rule_id_fk'] as $constraint) {
                DB::statement("ALTER TABLE order_pricing_snapshots DROP CONSTRAINT IF EXISTS {$constraint}");
            }
        }
        Schema::dropIfExists('order_pricing_snapshots');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commission_transactions')) {
            $driver = DB::connection()->getDriverName();

            Schema::create('commission_transactions', function (Blueprint $table) use ($driver): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->unsignedBigInteger('order_item_id')->nullable();
                $table->uuid('rule_id');
                $table->foreign('rule_id')->references('id')->on('commission_rules')->restrictOnDelete();
                $table->foreignId('seller_id')->constrained('companies')->restrictOnDelete();
                $table->decimal('base_amount', 15, 4);
                $table->char('base_currency', 3);
                $table->decimal('commission_amount', 15, 4);
                $table->char('commission_currency', 3);
                $table->decimal('net_to_seller', 15, 4)->nullable();
                $table->decimal('fx_rate', 18, 8)->nullable();
                $driver === 'pgsql'
                    ? $table->jsonb('snapshot')
                    : $table->json('snapshot');
                $table->timestamp('computed_at');
                $table->timestamps();

                $table->index('order_id', 'commission_transactions_order_id_idx');
                $table->index('order_item_id', 'commission_transactions_order_item_id_idx');
                $table->index(['seller_id', 'computed_at'], 'commission_transactions_seller_computed_at_idx');
                $table->index('rule_id', 'commission_transactions_rule_id_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_transactions');
    }
};

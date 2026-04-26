<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('order_items', function (Blueprint $table) use ($driver): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->string('item_type');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->uuid('parent_item_id')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3);
            $driver === 'pgsql'
                ? $table->jsonb('service_snapshot')->nullable()
                : $table->json('service_snapshot')->nullable();
            $driver === 'pgsql'
                ? $table->jsonb('passenger_data')->nullable()
                : $table->json('passenger_data')->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('status')->default('pending');
            $table->string('external_ref')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            $table->index('order_id');
            $table->index('parent_item_id');
            $table->index(['item_type', 'item_id']);
            $table->index('status');
            $table->index(['date_from', 'date_to']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreign('parent_item_id')->references('id')->on('order_items')->cascadeOnDelete();
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_item_type_check CHECK (item_type IN ('flight','hotel','transfer','car','excursion','visa','insurance','package'))");
            DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_status_check CHECK (status IN ('pending','reserved','confirmed','cancelled','failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};

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

        Schema::create('orders', function (Blueprint $table) use ($driver): void {
            $table->uuid('id')->primary();
            $table->string('order_number')->nullable()->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('buyer_type')->default('client');
            $table->string('status')->default('cart');
            $table->string('currency', 3);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->text('notes')->nullable();
            $driver === 'pgsql'
                ? $table->jsonb('metadata')->nullable()
                : $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();

            $table->index(['user_id', 'status']);
            $table->index(['company_id', 'status']);
            $table->index('status');
            $table->index('created_at');
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_buyer_type_check CHECK (buyer_type IN ('client','seller_b2b','guest'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('cart','pending_payment','paid','confirmed','cancelled','refunded','failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('gateway', 32)->default('stripe');
            $table->string('gateway_reference', 255)->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 32)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('payment_id');
            $table->index('gateway_reference');
            $table->index('event_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
    }
};

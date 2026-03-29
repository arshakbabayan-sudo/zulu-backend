<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bonuses')) {
            return;
        }

        Schema::create('bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->unique('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonuses');
    }
};

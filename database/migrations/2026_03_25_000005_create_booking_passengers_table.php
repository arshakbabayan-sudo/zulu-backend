<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_item_id')->nullable()->constrained('booking_items')->nullOnDelete();
            $table->foreignId('passenger_id')->constrained()->cascadeOnDelete();
            $table->string('seat_number', 10)->nullable();
            $table->text('special_requests')->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'passenger_id']);
            $table->index('booking_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_passengers');
    }
};

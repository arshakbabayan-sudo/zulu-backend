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
        Schema::create('flight_cabins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->string('cabin_class', 30);
            $table->unsignedInteger('seat_capacity_total')->default(0);
            $table->unsignedInteger('seat_capacity_available')->default(0);
            $table->decimal('adult_price', 10, 2);
            $table->decimal('child_price', 10, 2)->default(0);
            $table->decimal('infant_price', 10, 2)->default(0);
            $table->boolean('hand_baggage_included')->default(true);
            $table->string('hand_baggage_weight', 32)->nullable();
            $table->boolean('checked_baggage_included')->default(false);
            $table->string('checked_baggage_weight', 32)->nullable();
            $table->boolean('extra_baggage_allowed')->default(false);
            $table->text('baggage_notes')->nullable();
            $table->string('fare_family', 100)->nullable();
            $table->boolean('seat_map_available')->default(false);
            $table->string('seat_selection_policy', 100)->nullable();
            $table->timestamps();

            $table->unique(['flight_id', 'cabin_class']);
            $table->index('cabin_class');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_cabins');
    }
};

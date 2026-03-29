<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('booking_passengers');
        Schema::dropIfExists('booking_passenger');
        Schema::dropIfExists('passengers');

        Schema::create('passengers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('passport_number', 50)->nullable();
            $table->date('passport_expiry')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('passenger_type', 20)->default('adult');
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->timestamps();

            $table->index('passenger_type');
            $table->index('passport_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passengers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A B2C customer's saved travelers / companions (family, kids, colleagues) so
 * booking for several people doesn't mean re-typing passports every time.
 *
 * Distinct from the order-bound `passengers` snapshot (which has no owner) —
 * this is the customer's reusable address-book of people, including themselves
 * (`is_self`). The passport fields double as the core of the "Travel documents"
 * account section; extra document types (driver's licence, loyalty numbers)
 * live in `travel_documents`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_travelers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('nationality')->nullable();
            // Relationship to the account owner (self / spouse / child / colleague / other).
            $table->string('relationship', 32)->nullable();
            $table->boolean('is_self')->default(false);
            // Core passport (the most-used travel document); deeper docs in travel_documents.
            $table->string('passport_number')->nullable();
            $table->string('passport_country')->nullable();
            $table->date('passport_expiry')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_travelers');
    }
};

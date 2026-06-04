<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A B2C customer's own travel documents (account "Travel documents" section):
 * passport, ID card, driver's licence, airline/hotel loyalty numbers, etc.
 *
 * Flexible by `type` so one table holds every document kind. Distinct from the
 * per-person passport on `saved_travelers` (that pre-fills passenger data); this
 * is the owner's personal document wallet, reused at checkout + for car rentals
 * (licence) and frequent-flyer crediting (loyalty numbers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // passport / id_card / drivers_license / loyalty / frequent_flyer / other
            $table->string('type', 32);
            // Free label, e.g. "Aeroflot Bonus" for a loyalty number.
            $table->string('label')->nullable();
            $table->string('number')->nullable();
            $table->string('issuing_country')->nullable();
            $table->string('holder_name')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            // Optional uploaded scan (stored via the file pipeline).
            $table->string('file_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_documents');
    }
};

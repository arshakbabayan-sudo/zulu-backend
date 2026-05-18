<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.2 — Block dates.
 *
 * Operators block sales dates per inventory item to prevent overbooking when
 * capacity isn't available (sold out, maintenance, manual hold). Stored
 * generically so it covers all current and future inventory types.
 *
 * Booking-time enforcement (the booking flow refuses dates where a row
 * exists for the matched item) is wired in a follow-up alongside individual
 * item type bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // hotel | flight | car | transfer | excursion | visa | package | offer
            $table->string('item_type', 32);
            $table->unsignedBigInteger('item_id')->nullable();
            $table->date('blocked_from');
            $table->date('blocked_to');
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'item_type', 'item_id']);
            $table->index(['item_type', 'item_id', 'blocked_from', 'blocked_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_dates');
    }
};

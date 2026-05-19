<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clock-in / clock-out punch tracking — the shift-attendance counterpart to
 * time-off (`time_off_records`). Each row is one shift: an in-stamp and an
 * out-stamp. Open shifts (no out-stamp) flag who is currently on the clock.
 * Useful for payroll roll-ups (hours_worked) and live attendance views.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('punched_in_at');
            $table->timestamp('punched_out_at')->nullable();
            // Minutes worked, denormalised on clock-out for fast roll-ups.
            $table->unsignedInteger('minutes_worked')->nullable();
            // self | manager | system — who created the entry
            $table->string('source', 32)->default('self');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'punched_in_at']);
            $table->index(['user_id', 'punched_in_at']);
            // Partial-ish index: lookups for "still on the clock" rows are
            // narrowed by user_id first, so plain composite is enough.
            $table->index(['user_id', 'punched_out_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_punches');
    }
};

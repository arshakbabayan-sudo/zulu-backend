<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.13 — Non-service hours (HR time-off / non-availability log).
 *
 * Captures both formal time-off requests and informal non-service-hours
 * blocks for an employee. Hours-not-worked records are useful for payroll
 * (Phase 7.15) and attendance reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_off_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // vacation | sick | personal | unpaid | other
            $table->string('type', 32);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('hours_total', 6, 2)->nullable();
            $table->text('notes')->nullable();
            // pending | approved | rejected | cancelled
            $table->string('status', 32)->default('pending');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['user_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_off_records');
    }
};

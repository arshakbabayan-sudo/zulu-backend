<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.10 — cases & assignments.
 *
 * Heavier-weight workflow than /support/tickets — cases carry priority,
 * an assigned case officer, opened/closed audit trail, and structured
 * status transitions. Future iterations layer SLA tracking on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number', 32)->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('title', 255);
            $table->text('description');
            // open | in_progress | pending_customer | resolved | closed | escalated
            $table->string('status', 32)->default('open');
            // low | normal | high | urgent
            $table->string('priority', 16)->default('normal');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index('assigned_to_user_id');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cases');
    }
};

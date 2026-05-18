<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.7 — agent → operator special booking requests.
 *
 * Inbox for off-catalog requests: custom packages, group bookings,
 * special rates, etc. Status moves open → in_progress → closed
 * (resolved or rejected). A future part 2 adds reply threading;
 * this initial schema captures the request itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_operator_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requester_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('target_company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('subject', 255);
            $table->text('body');
            // open | in_progress | resolved | rejected
            $table->string('status', 32)->default('open');
            $table->text('resolution_notes')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['target_company_id', 'status']);
            $table->index(['requester_company_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_operator_requests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reply thread for agent ↔ operator requests (Phase 7.7 follow-up — Item 14).
 *
 * Replaces the single subject + body view on /bucket3/requests with a real
 * threaded conversation. Each row is one message; the first row is usually
 * a duplicate of the original request body, subsequent rows are
 * back-and-forth replies between requester and target company staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('request_messages')) {
            Schema::create('request_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('request_id')
                    ->constrained('agent_operator_requests')
                    ->cascadeOnDelete();
                $table->foreignId('sender_id')
                    ->constrained('users')
                    ->cascadeOnDelete();
                $table->text('body');
                $table->timestamps();

                $table->index(['request_id', 'created_at'], 'request_messages_request_id_created_at_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('request_messages');
    }
};

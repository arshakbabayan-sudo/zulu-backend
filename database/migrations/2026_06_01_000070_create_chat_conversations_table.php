<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal chat — conversations (2026-06-01).
 *
 * Company-scoped chat (Arshak option Բ: anyone in the same company can message
 * each other + their lead). A conversation belongs to a company and is either
 * a 1:1 "direct" thread or a named "group". Polling-based for Phase 1 (no
 * websockets); last_message_at lets the list sort by recency cheaply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('type')->default('direct'); // direct | group
            $table->string('title')->nullable();        // for group chats
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};

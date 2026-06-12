<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap §4 — customer ↔ support chat.
 *
 * chat_conversations gains type='customer' rows: started by a B2C customer
 * from the zulu.am floating widget, answered by PLATFORM staff from the
 * admin Chat page. Such threads belong to no tenant company → company_id
 * becomes nullable; customer_user_id pins the thread owner (one support
 * thread per customer, reused forever).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->change();
            $table->foreignId('customer_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['type', 'last_message_at'], 'chat_conv_type_last_idx');
            $table->index(['customer_user_id'], 'chat_conv_customer_idx');
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropIndex('chat_conv_type_last_idx');
            $table->dropIndex('chat_conv_customer_idx');
            $table->dropConstrainedForeignId('customer_user_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social inbox — incoming Facebook Messenger (and later Instagram Direct)
 * conversations from the company's Meta pages, surfaced in the CRM.
 *
 * A "conversation" is one customer (identified by their page-scoped id / PSID)
 * talking to one page. Messages hang off it in both directions. Channel is a
 * plain string ('facebook' now, 'instagram' next) so the same tables serve both.
 *
 * Deliberately SEPARATE from chat_conversations/chat_messages (internal staff +
 * B2C support chat): those are user↔user with a Sanctum identity on both ends;
 * a Messenger contact has no ZULU user, only a PSID, plus channel/attachment
 * metadata that would pollute the internal chat schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_conversations', function (Blueprint $table): void {
            $table->id();
            // 'facebook' | 'instagram'
            $table->string('channel', 20)->default('facebook');
            // The Meta page that received the message (e.g. Anie Travel Armenia).
            $table->string('page_id', 64);
            // Page-scoped id of the customer — stable per (page, person).
            $table->string('psid', 64);
            // Best-effort display name (fetched from the Graph API when possible).
            $table->string('customer_name')->nullable();
            // Optional link to a CRM lead once the enquiry is triaged.
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            // Company that owns the page — nullable until page→company mapping.
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            // Sort key + unread badge for the inbox list.
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // One conversation per (channel, page, person).
            $table->unique(['channel', 'page_id', 'psid']);
            $table->index('last_message_at');
        });

        Schema::create('social_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('social_conversations')->cascadeOnDelete();
            // 'in' = from the customer, 'out' = reply sent by our staff.
            $table->string('direction', 3);
            // Meta's message id (mid). Unique when present so a re-delivered
            // webhook event can't store the same message twice.
            $table->string('external_message_id', 191)->nullable();
            // Sender PSID for inbound; null for our outbound replies.
            $table->string('sender_psid', 64)->nullable();
            $table->text('text')->nullable();
            // Images/files (e.g. a passport photo the customer sends) — array of
            // {type,url}. Stored as JSON, downloaded/persisted in a later step.
            $table->json('attachments')->nullable();
            // Full webhook payload for this event, for debugging/audit.
            $table->json('raw')->nullable();
            // Which staff user sent an outbound reply (null for inbound).
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('external_message_id');
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_messages');
        Schema::dropIfExists('social_conversations');
    }
};

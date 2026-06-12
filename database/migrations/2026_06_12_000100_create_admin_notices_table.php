<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap §4 (2026-06-12) — Settings → System notifications CUD.
 *
 * An admin NOTICE is an authored platform announcement (maintenance window,
 * feature news…) with an audience selector and optional scheduling. Sending a
 * notice fans out per-user Notification rows through the SAME bulk machinery
 * the /bulk-notifications page uses (SendBulkNotificationJob), so delivery,
 * channels and per-user history stay on the one existing pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notices', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->string('type', 32)->default('announcement'); // maintenance|announcement|info
            $table->string('audience', 32); // everyone|all_b2c|all_staff|by_company
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->json('channels')->nullable();
            $table->string('priority', 16)->default('normal');
            $table->timestamp('scheduled_for')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('sent_count')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The due-notice sweep: unsent + active + schedule reached.
            $table->index(['sent_at', 'is_active', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notices');
    }
};

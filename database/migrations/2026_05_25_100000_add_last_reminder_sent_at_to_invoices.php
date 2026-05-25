<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance group v2 — Phase 2d.
 *
 * Adds a `last_reminder_sent_at` timestamp on invoices so the "Send reminder"
 * IconButton on overdue rows can:
 *   1. Throttle reminders (don't spam if last one was < N hours ago)
 *   2. Surface the most-recent reminder date in admin tooltips later.
 *
 * Nullable so existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->timestamp('last_reminder_sent_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('last_reminder_sent_at');
        });
    }
};

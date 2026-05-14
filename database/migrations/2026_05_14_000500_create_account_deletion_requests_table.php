<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GDPR Step 2.1 — user-initiated account deletion with a 30-day grace
     * window. The actual purge runs as a scheduled job once the window
     * expires; until then the row is recoverable.
     *
     * Flow:
     *   1. user posts `DELETE /api/account` → row inserted with
     *      `status = pending_confirmation`, `confirmation_token` set,
     *      email queued.
     *   2. user clicks the email link → `confirmed_at` stamped, status
     *      becomes `scheduled`, `scheduled_for` = now + 30 days, user's
     *      `deleted_at` (soft delete) is set.
     *   3. user changes mind → `POST /api/account/restore` clears
     *      `deleted_at` and marks the request `cancelled`.
     *   4. Scheduler at `scheduled_for` purges the user + dependent rows
     *      and marks the request `completed`.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasTable('account_deletion_requests')) {
            return;
        }

        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending_confirmation', 'scheduled', 'cancelled', 'completed'])->default('pending_confirmation');
            $table->string('confirmation_token', 96)->nullable()->unique();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('scheduled_for');
        });

        // Soft-delete column on users so account stays recoverable during
        // the 30-day window; auth flows already filter by `status` which
        // we'll flip to `pending_deletion` once confirmed.
        if (! Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');

        if (Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};

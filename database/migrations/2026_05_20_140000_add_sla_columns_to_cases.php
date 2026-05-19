<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA tracking on cases (Phase 7.10 A2 follow-up).
 *
 * sla_due_at: deadline for first / next response, computed from priority
 *   at case creation (urgent=2h, high=4h, normal=24h, low=72h).
 * escalated_at: stamped by `cases:escalate-overdue` scheduled command when
 *   sla_due_at < now() and status is still in {open, in_progress,
 *   pending_customer}. Once escalated, the command leaves it alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->timestamp('sla_due_at')->nullable()->after('priority');
            $table->timestamp('escalated_at')->nullable()->after('sla_due_at');

            $table->index('sla_due_at');
            $table->index('escalated_at');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropIndex(['sla_due_at']);
            $table->dropIndex(['escalated_at']);
            $table->dropColumn(['sla_due_at', 'escalated_at']);
        });
    }
};

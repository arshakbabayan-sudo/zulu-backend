<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds indexes on foreign-key columns that were declared without explicit
 * `->index()` in earlier migrations. Discovered during the 2026-05-20
 * platform audit (docs/audits/full-platform-audit-2026-05-20.md section 2).
 *
 * PostgreSQL does NOT auto-index foreign keys (unlike MySQL InnoDB), so
 * every `foreignId()` that lacks an explicit index becomes a sequential
 * scan target on JOINs and filtered queries.
 *
 * Index choices are conservative — single-column indexes only. Composite
 * indexes that already cover one of these columns (e.g.
 * `agent_operator_requests (target_company_id, status)`) are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_operator_requests')) {
            Schema::table('agent_operator_requests', function (Blueprint $t): void {
                if (! $this->hasIndex('agent_operator_requests', 'agent_operator_requests_requester_user_id_index')) {
                    $t->index('requester_user_id');
                }
                if (! $this->hasIndex('agent_operator_requests', 'agent_operator_requests_resolved_by_user_id_index')) {
                    $t->index('resolved_by_user_id');
                }
            });
        }

        if (Schema::hasTable('operator_agent_commission')) {
            Schema::table('operator_agent_commission', function (Blueprint $t): void {
                if (! $this->hasIndex('operator_agent_commission', 'operator_agent_commission_agent_company_id_index')) {
                    $t->index('agent_company_id');
                }
                if (! $this->hasIndex('operator_agent_commission', 'operator_agent_commission_created_by_user_id_index')) {
                    $t->index('created_by_user_id');
                }
            });
        }

        // saved_items is queried hot path on /api/account/saved-items
        if (Schema::hasTable('saved_items') && ! $this->hasIndex('saved_items', 'saved_items_user_id_status_index')) {
            Schema::table('saved_items', function (Blueprint $t): void {
                $t->index(['user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_operator_requests')) {
            Schema::table('agent_operator_requests', function (Blueprint $t): void {
                if ($this->hasIndex('agent_operator_requests', 'agent_operator_requests_requester_user_id_index')) {
                    $t->dropIndex('agent_operator_requests_requester_user_id_index');
                }
                if ($this->hasIndex('agent_operator_requests', 'agent_operator_requests_resolved_by_user_id_index')) {
                    $t->dropIndex('agent_operator_requests_resolved_by_user_id_index');
                }
            });
        }

        if (Schema::hasTable('operator_agent_commission')) {
            Schema::table('operator_agent_commission', function (Blueprint $t): void {
                if ($this->hasIndex('operator_agent_commission', 'operator_agent_commission_agent_company_id_index')) {
                    $t->dropIndex('operator_agent_commission_agent_company_id_index');
                }
                if ($this->hasIndex('operator_agent_commission', 'operator_agent_commission_created_by_user_id_index')) {
                    $t->dropIndex('operator_agent_commission_created_by_user_id_index');
                }
            });
        }

        if (Schema::hasTable('saved_items') && $this->hasIndex('saved_items', 'saved_items_user_id_status_index')) {
            Schema::table('saved_items', function (Blueprint $t): void {
                $t->dropIndex('saved_items_user_id_status_index');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        $rows = \DB::select(
            "SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?",
            [$table, $index]
        );
        return ! empty($rows);
    }
};

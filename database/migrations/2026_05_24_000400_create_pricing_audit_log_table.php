<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 / Step C.1 — `pricing_audit_log` table.
 *
 * Every change to `pricing_rules` or `money_flow_terms` writes one row
 * here. Captures the actor, the before/after JSON, and an optional
 * free-form reason note.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pricing_audit_log')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('pricing_audit_log', function (Blueprint $table) use ($driver): void {
            $table->bigIncrements('id');

            $table->string('entity_type', 32); // pricing_rule|money_flow_term
            $table->string('entity_id', 36); // uuid for pricing_rule, bigint-as-string for money_flow_term
            $table->string('action', 32); // created|updated|deactivated|reactivated|deleted

            $driver === 'pgsql'
                ? $table->jsonb('old_values')->nullable()
                : $table->json('old_values')->nullable();
            $driver === 'pgsql'
                ? $table->jsonb('new_values')->nullable()
                : $table->json('new_values')->nullable();

            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            // immutable: no updated_at

            $table->index(['entity_type', 'entity_id'], 'pricing_audit_log_entity_idx');
            $table->index('changed_by', 'pricing_audit_log_actor_idx');
            $table->index('created_at', 'pricing_audit_log_time_idx');
        });

        if ($driver === 'pgsql') {
            DB::statement(
                "ALTER TABLE pricing_audit_log ADD CONSTRAINT pricing_audit_log_entity_type_chk
                CHECK (entity_type IN ('pricing_rule','money_flow_term'))"
            );
            DB::statement(
                "ALTER TABLE pricing_audit_log ADD CONSTRAINT pricing_audit_log_action_chk
                CHECK (action IN ('created','updated','deactivated','reactivated','deleted'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasTable('pricing_audit_log')) {
            foreach (['pricing_audit_log_entity_type_chk', 'pricing_audit_log_action_chk'] as $constraint) {
                DB::statement("ALTER TABLE pricing_audit_log DROP CONSTRAINT IF EXISTS {$constraint}");
            }
        }
        Schema::dropIfExists('pricing_audit_log');
    }
};

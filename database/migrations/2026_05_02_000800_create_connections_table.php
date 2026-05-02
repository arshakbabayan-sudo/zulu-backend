<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('connections', function (Blueprint $table) use ($driver): void {
            $table->uuid('id')->primary();

            $table->string('type'); // supplier_reseller | mutual | white_label | partner_feed

            $table->unsignedBigInteger('seller_a_company_id'); // proposer
            $table->unsignedBigInteger('seller_b_company_id'); // acceptor

            $table->string('direction')->default('a_to_b'); // a_to_b | b_to_a | both

            $table->uuid('partner_agreement_id')->nullable(); // FK contracts
            $table->uuid('commission_override_rule_id')->nullable(); // FK commission_rules

            $driver === 'pgsql'
                ? $table->jsonb('share_scope')
                : $table->json('share_scope');

            $driver === 'pgsql'
                ? $table->jsonb('territorial_scope')->nullable()
                : $table->json('territorial_scope')->nullable();

            $driver === 'pgsql'
                ? $table->jsonb('display_options')
                : $table->json('display_options');

            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();

            $table->string('status')->default('proposed'); // proposed | active | paused | terminated | rejected

            $table->timestamp('proposed_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->text('termination_reason')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->unsignedBigInteger('proposed_by_user_id');
            $table->unsignedBigInteger('responded_by_user_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('seller_a_company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('seller_b_company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('partner_agreement_id')->references('id')->on('contracts')->nullOnDelete();
            $table->foreign('commission_override_rule_id')->references('id')->on('commission_rules')->nullOnDelete();
            $table->foreign('proposed_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('responded_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index('seller_a_company_id');
            $table->index('seller_b_company_id');
            $table->index('status');
            $table->index('type');
            $table->index(['seller_a_company_id', 'status']);
            $table->index(['seller_b_company_id', 'status']);
            $table->index('effective_from');
            $table->index('effective_to');
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE connections ADD CONSTRAINT connections_type_check CHECK (type IN ('supplier_reseller','mutual','white_label','partner_feed'))");
            DB::statement("ALTER TABLE connections ADD CONSTRAINT connections_direction_check CHECK (direction IN ('a_to_b','b_to_a','both'))");
            DB::statement("ALTER TABLE connections ADD CONSTRAINT connections_status_check CHECK (status IN ('proposed','active','paused','terminated','rejected'))");
            DB::statement("ALTER TABLE connections ADD CONSTRAINT connections_self_ref_check CHECK (seller_a_company_id <> seller_b_company_id)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};

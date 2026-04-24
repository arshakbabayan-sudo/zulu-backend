<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commission_resolution_log')) {
            $driver = DB::connection()->getDriverName();

            Schema::create('commission_resolution_log', function (Blueprint $table) use ($driver): void {
                $table->bigIncrements('id');
                $table->uuid('transaction_id');
                $table->foreign('transaction_id')
                    ->references('id')
                    ->on('commission_transactions')
                    ->cascadeOnDelete();
                $driver === 'pgsql'
                    ? $table->jsonb('candidate_rules')
                    : $table->json('candidate_rules');
                $table->uuid('chosen_rule_id');
                $table->foreign('chosen_rule_id')->references('id')->on('commission_rules')->restrictOnDelete();
                $table->text('reason');
                $table->timestamps();

                $table->index('transaction_id', 'commission_resolution_log_transaction_id_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_resolution_log');
    }
};

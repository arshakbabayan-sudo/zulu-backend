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

        Schema::create('audit_logs', function (Blueprint $table) use ($driver): void {
            $table->uuid('id')->primary();
            $table->string('category'); // CHECK below
            $table->string('actor_type')->default('user'); // user | system | api_client
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name_snapshot');
            $table->string('subject_type');
            $table->string('subject_id');
            $table->string('action');
            $driver === 'pgsql'
                ? $table->jsonb('changes')->nullable()
                : $table->json('changes')->nullable();
            $driver === 'pgsql'
                ? $table->jsonb('context')->nullable()
                : $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('session_id', 191)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('hash', 64); // SHA-256 hex
            $table->string('previous_log_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index('action');
            $table->index('category');
            $table->index('created_at');
            $table->index('request_id');
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_category_check CHECK (category IN ('auth','data_change','financial','approval','contract','support','admin_actions','api','security','system'))");
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check CHECK (actor_type IN ('user','system','api_client'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

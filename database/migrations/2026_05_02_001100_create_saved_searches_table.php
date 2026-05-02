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

        Schema::create('saved_searches', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name')->nullable();
            $table->string('module_type')->nullable();
            $table->string('query_string', 500)->nullable();

            $driver === 'pgsql'
                ? $table->jsonb('filters')
                : $table->json('filters');

            $table->boolean('alerts_enabled')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('result_count_at_save')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
            $table->index(['user_id', 'module_type']);
        });

        Schema::create('search_query_log', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('query_string', 500)->nullable();
            $table->string('module_type')->nullable();

            $driver === 'pgsql'
                ? $table->jsonb('filters')->nullable()
                : $table->json('filters')->nullable();

            $table->unsignedInteger('result_count')->default(0);
            $table->timestamp('happened_at');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('happened_at');
            $table->index('query_string');
            $table->index('module_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_query_log');
        Schema::dropIfExists('saved_searches');
    }
};

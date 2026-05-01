<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('contract_versions', function (Blueprint $table) use ($driver): void {
            $table->bigIncrements('id');
            $table->uuid('contract_id');
            $table->unsignedInteger('version_number');
            $driver === 'pgsql'
                ? $table->jsonb('snapshot')
                : $table->json('snapshot');
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->foreign('changed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['contract_id', 'version_number']);
            $table->index('contract_id');
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_versions');
    }
};

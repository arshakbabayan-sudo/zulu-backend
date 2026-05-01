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

        Schema::create('voucher_verification_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('voucher_id');
            $table->timestamp('scanned_at');
            $table->string('ip', 45);
            $table->string('user_agent', 500)->nullable();
            $table->string('location')->nullable();
            $table->string('result');
            $table->timestamps();

            $table->foreign('voucher_id')->references('id')->on('vouchers')->cascadeOnDelete();

            $table->index('voucher_id');
            $table->index('scanned_at');
            $table->index('result');
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE voucher_verification_logs ADD CONSTRAINT voucher_verification_logs_result_check CHECK (result IN ('valid','used','void','invalid','expired'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_verification_logs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('user_two_factor', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->text('secret_encrypted'); // base32, encrypted at rest

            $driver === 'pgsql'
                ? $table->jsonb('recovery_codes_encrypted')->nullable()
                : $table->json('recovery_codes_encrypted')->nullable();

            $table->timestamp('enabled_at')->nullable(); // null = setup pending
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_two_factor');
    }
};

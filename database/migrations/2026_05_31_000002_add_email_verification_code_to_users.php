<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2C email verification at signup (Arshak 2026-05-31).
 *
 * Replaces Laravel's link-based email verification (sendEmailVerificationNotification)
 * with a 6-digit code flow that matches the rest of the platform (2FA, password
 * reset). Users register, receive a code in their inbox, then enter it on a
 * verify-email page to flip their `email_verified_at` to non-null.
 *
 * Schema:
 *   email_verification_code_hash       sha256 of the latest code, nullable
 *   email_verification_code_expires_at 10-minute TTL, nullable
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email_verification_code_hash', 64)->nullable()->after('email_verified_at');
            $table->timestamp('email_verification_code_expires_at')->nullable()->after('email_verification_code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['email_verification_code_hash', 'email_verification_code_expires_at']);
        });
    }
};

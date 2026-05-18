<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.14 — per-user PIN for sensitive admin actions.
 *
 * Stores a bcrypt hash of the user's 4-8 digit PIN. Verification endpoint
 * compares submitted PIN against the hash. Sensitive admin actions
 * (publish, terminate, bulk refund) call the verify endpoint before
 * proceeding — wiring into individual actions lands as those features
 * adopt PIN gating; this migration just lands the storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin_hash', 255)->nullable()->after('password');
            $table->timestamp('pin_set_at')->nullable()->after('pin_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pin_hash', 'pin_set_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase Զ.15 / Item 15 follow-up — track last login per user.
 *
 * Surfaces:
 *   - `/admin-redesign/users` "Active today" stat card (was "—")
 *   - `/admin-redesign/profile` "Last seen" meta row (was "—")
 *
 * Updated by the AuthController login flow on each successful login.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('last_login_at')->nullable()->after('email_verified_at');
                $table->index('last_login_at', 'users_last_login_at_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex('users_last_login_at_idx');
                $table->dropColumn('last_login_at');
            });
        }
    }
};

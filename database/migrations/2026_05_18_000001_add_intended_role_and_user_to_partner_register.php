<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-8 partner-register fix.
 *
 *   users.intended_role — set at /api/register when the visitor came in via
 *     /register/operator or /register/agent. Tells admins (on the
 *     applications list) and us (on post-login redirect) what the user is
 *     trying to become BEFORE their CompanyApplication has been approved.
 *
 *   company_applications.user_id — links the application back to the user
 *     who registered in step 1. When admin approves, the existing user gets
 *     the Company attached instead of a brand-new user being minted with a
 *     temp password. Falls back to the old "create new user" path when the
 *     column is null (old applications, anonymous submissions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('intended_role', 32)->nullable()->after('status');
            $table->index('intended_role');
        });

        Schema::table('company_applications', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_applications', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['intended_role']);
            $table->dropColumn('intended_role');
        });
    }
};

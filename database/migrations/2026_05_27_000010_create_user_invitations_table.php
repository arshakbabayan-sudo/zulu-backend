<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bucket D Phase D.1 — invitation system for the «Add employee» flow.
 *
 * When a company manager (company_admin / operator_admin) adds an employee
 * via POST /companies/{id}/users with `mode=invite`, we pre-create the User
 * row with a random password + status='invited', then store this invitation
 * row carrying a one-time token. The invitee opens an emailed link that hits
 * /auth/accept-invitation/{token} to set their own password and activate.
 *
 * Token is single-use (accepted_at marks it consumed). Default expiry is 7
 * days from creation; cleanup job can prune past-expiry-and-unaccepted rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('company_id');
            $table->index(['accepted_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};

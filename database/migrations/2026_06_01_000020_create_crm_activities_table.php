<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM activities — the unified interaction log (calls / emails / meetings /
 * tasks / notes) shown in the CRM "Activities" tab and on a customer card's
 * "Communication" tab. Mirrors docs/admin_designe/new-admin-designe/files/
 * crm.html "Activities".
 *
 * A loose polymorphic link (subject_type + subject_id) lets an activity hang
 * off a customer, a deal, or a lead without a hard FK per type. owner_user_id
 * is the employee who logged/owns it (feeds the Team rollup later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->default('note');     // call|email|meeting|task|note
            $table->string('subject');
            $table->text('body')->nullable();
            // Loose link to what this activity is about.
            $table->string('subject_type')->nullable();  // customer|deal|lead
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status')->default('open');    // open|done|overdue
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
    }
};

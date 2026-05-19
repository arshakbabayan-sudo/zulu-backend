<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reply thread for cases (Phase 7.10 follow-up A1).
 *
 * Each row is one message in a case's conversation — from the case opener,
 * the assignee, a manager, or any party with visibility. The first row is
 * usually a duplicate of the case description; subsequent rows are
 * back-and-forth ("Need more detail", "Attached doc", "This is how we
 * resolved it").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            // internal = visible only to staff (private note); public = visible to opener too
            $table->string('visibility', 16)->default('public');
            // Optional attachments stored as jsonb array of {name, url, size}
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_replies');
    }
};

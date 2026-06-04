<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin notes attached to a user (B2C customer or staff).
 *
 * Powers the "Notes" sub-tab on the Management → B2C customers detail view
 * (admin v3 2026-06-04 inline detail, `6_management_new.html` lines 1226-1243)
 * and the future "Add note" hero action on the Unverified detail view.
 *
 * Each row is an internal admin note authored by another admin user. Soft-delete
 * keeps an audit trail (we never lose the original wording) and the author FK
 * uses nullOnDelete so removing an admin doesn't cascade-erase their notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notes');
    }
};

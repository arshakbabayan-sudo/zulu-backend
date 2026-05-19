<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — social login (OAuth) provider linkage.
 *
 * Adds nullable provider identifiers per user so we can link an existing
 * email-based account to a Google or Facebook login on first OAuth callback
 * (match by email), or create a new user when no match exists. The
 * `oauth_provider` column records the source the user last signed in
 * through, mainly for UX hints ("You normally sign in with Google").
 *
 * Unique partial-style guards via `nullable + unique` indexes ensure one
 * Google/Facebook account maps to at most one ZULU user. PG NULLs do not
 * collide on unique indexes by default, so the partial-style is implicit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_id', 64)->nullable()->after('avatar');
            $table->string('facebook_id', 64)->nullable()->after('google_id');
            $table->string('oauth_provider', 16)->nullable()->after('facebook_id');

            $table->unique('google_id');
            $table->unique('facebook_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['google_id']);
            $table->dropUnique(['facebook_id']);
            $table->dropColumn(['google_id', 'facebook_id', 'oauth_provider']);
        });
    }
};

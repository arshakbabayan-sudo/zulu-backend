<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `location` to users (Phase 2 profile follow-up).
 *
 * Surfaces on `/admin-redesign/profile` meta row (was "—") and the Edit
 * profile drawer. Free-form short string — city, country, or anything the
 * user wants to advertise to colleagues. Not used for booking logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'location')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('location', 120)->nullable()->after('nationality');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'location')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('location');
            });
        }
    }
};

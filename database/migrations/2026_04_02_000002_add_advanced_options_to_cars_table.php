<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAR rental Step C1 — advanced options (normalized JSON blob).
 * Nullable for backward compatibility; API merges defaults when null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->json('advanced_options')->nullable()->after('availability_status');
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->dropColumn('advanced_options');
        });
    }
};

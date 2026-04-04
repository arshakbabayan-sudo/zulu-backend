<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration summary (EXCURSIONS Step B1 — core mapping):
 * Adds nullable geography, taxonomy, tour copy, schedule window, language, capacity, status, and
 * availability flags on `excursions`. Existing `location`, `duration`, `group_size` unchanged;
 * legacy rows stay valid with NULLs / boolean defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('excursions', function (Blueprint $table) {
            $table->string('country')->nullable()->after('group_size');
            $table->string('city')->nullable()->after('country');

            $table->string('general_category')->nullable()->after('city');
            $table->string('category')->nullable()->after('general_category');
            $table->string('excursion_type')->nullable()->after('category');

            $table->string('tour_name')->nullable()->after('excursion_type');
            $table->text('overview')->nullable()->after('tour_name');

            $table->dateTime('starts_at')->nullable()->after('overview');
            $table->dateTime('ends_at')->nullable()->after('starts_at');

            $table->string('language')->nullable()->after('ends_at');
            $table->unsignedInteger('ticket_max_count')->nullable()->after('language');

            $table->string('status')->nullable()->after('ticket_max_count');

            $table->boolean('is_available')->default(true)->after('status');
            $table->boolean('is_bookable')->default(true)->after('is_available');
        });
    }

    public function down(): void
    {
        Schema::table('excursions', function (Blueprint $table) {
            $table->dropColumn([
                'country',
                'city',
                'general_category',
                'category',
                'excursion_type',
                'tour_name',
                'overview',
                'starts_at',
                'ends_at',
                'language',
                'ticket_max_count',
                'status',
                'is_available',
                'is_bookable',
            ]);
        });
    }
};

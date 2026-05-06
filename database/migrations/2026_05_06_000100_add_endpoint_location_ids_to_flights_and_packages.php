<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add structured endpoint-location FKs to flights + packages so the customer
 * search loop (location_id-based filtering via the Location tree) works
 * uniformly across every content module.
 *
 * Flights: keeps the existing single `location_id` column for back-compat,
 * but introduces departure_location_id + arrival_location_id which mirror
 * the transfers table (origin_location_id / destination_location_id).
 *
 * Packages: introduces destination_location_id alongside the legacy
 * destination_country / destination_city text columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            if (! Schema::hasColumn('flights', 'departure_location_id')) {
                $table->foreignId('departure_location_id')
                    ->nullable()
                    ->after('location_id')
                    ->constrained('locations')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('flights', 'arrival_location_id')) {
                $table->foreignId('arrival_location_id')
                    ->nullable()
                    ->after('departure_location_id')
                    ->constrained('locations')
                    ->nullOnDelete();
            }
        });

        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'destination_location_id')) {
                $table->foreignId('destination_location_id')
                    ->nullable()
                    ->after('destination_country')
                    ->constrained('locations')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            if (Schema::hasColumn('flights', 'arrival_location_id')) {
                $table->dropConstrainedForeignId('arrival_location_id');
            }
            if (Schema::hasColumn('flights', 'departure_location_id')) {
                $table->dropConstrainedForeignId('departure_location_id');
            }
        });

        Schema::table('packages', function (Blueprint $table) {
            if (Schema::hasColumn('packages', 'destination_location_id')) {
                $table->dropConstrainedForeignId('destination_location_id');
            }
        });
    }
};

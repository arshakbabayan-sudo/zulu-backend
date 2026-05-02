<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restore pickup_location + dropoff_location string columns on `cars` table.
 *
 * The 2026-04-16 "draft_drop_legacy_location_columns_after_cutover" migration
 * dropped these columns assuming car services would migrate to the Location
 * relation tree. However the cleanup of dependent code (Car model fillable,
 * OfferNormalizationService::normalizeCarOffer, validation rules) was not
 * completed — application code still references these columns.
 *
 * Result: Sprint 1 cleanup miss, manifested as test failures (CarWriteApiTest,
 * OfferNormalizationServiceTest, CatalogOfferDetailNormalizationApiTest) and
 * production normalization returning null for from/to_location on car offers.
 *
 * Resolution path: restore columns as nullable strings. Long-term move to
 * Location-relation-derived from/to is a separate ADR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            if (! Schema::hasColumn('cars', 'pickup_location')) {
                $table->string('pickup_location')->nullable()->after('offer_id');
            }
            if (! Schema::hasColumn('cars', 'dropoff_location')) {
                $table->string('dropoff_location')->nullable()->after('pickup_location');
            }
        });

        Schema::table('flights', function (Blueprint $table) {
            if (! Schema::hasColumn('flights', 'departure_city')) {
                $table->string('departure_city')->nullable()->after('departure_airport_code');
            }
            if (! Schema::hasColumn('flights', 'arrival_city')) {
                $table->string('arrival_city')->nullable()->after('arrival_airport_code');
            }
        });

        Schema::table('transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('transfers', 'pickup_city')) {
                $table->string('pickup_city')->nullable();
            }
            if (! Schema::hasColumn('transfers', 'dropoff_city')) {
                $table->string('dropoff_city')->nullable();
            }
        });

        Schema::table('excursions', function (Blueprint $table) {
            if (! Schema::hasColumn('excursions', 'location')) {
                $table->string('location')->nullable();
            }
            if (! Schema::hasColumn('excursions', 'country')) {
                $table->string('country')->nullable();
            }
            if (! Schema::hasColumn('excursions', 'city')) {
                $table->string('city')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('excursions', function (Blueprint $table) {
            foreach (['city', 'country', 'location'] as $col) {
                if (Schema::hasColumn('excursions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('transfers', function (Blueprint $table) {
            foreach (['dropoff_city', 'pickup_city'] as $col) {
                if (Schema::hasColumn('transfers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('flights', function (Blueprint $table) {
            foreach (['arrival_city', 'departure_city'] as $col) {
                if (Schema::hasColumn('flights', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('cars', function (Blueprint $table) {
            foreach (['dropoff_location', 'pickup_location'] as $col) {
                if (Schema::hasColumn('cars', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DRAFT ONLY: do not run until Step 10 QA sign-off.
 *
 * This migration removes legacy text-based location columns after full cutover
 * to location_id / origin_location_id / destination_location_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropColumnsIfExist('hotels', ['country', 'region_or_state', 'city']);
        $this->dropColumnsIfExist('visas', ['country', 'country_id']);
        $this->dropColumnsIfExist('excursions', ['location', 'country', 'city']);
        $this->dropColumnsIfExist('transfers', ['pickup_country', 'pickup_city', 'dropoff_country', 'dropoff_city']);
        $this->dropColumnsIfExist('flights', ['departure_country', 'departure_city', 'arrival_country', 'arrival_city']);
        $this->dropColumnsIfExist('cars', ['pickup_location', 'dropoff_location']);
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            if (! Schema::hasColumn('hotels', 'country')) {
                $table->string('country', 120)->nullable()->after('star_rating');
            }
            if (! Schema::hasColumn('hotels', 'region_or_state')) {
                $table->string('region_or_state')->nullable()->after('country');
            }
            if (! Schema::hasColumn('hotels', 'city')) {
                $table->string('city')->nullable()->after('region_or_state');
            }
        });

        Schema::table('visas', function (Blueprint $table) {
            if (! Schema::hasColumn('visas', 'country_id')) {
                $table->unsignedBigInteger('country_id')->nullable()->after('offer_id');
            }
            if (! Schema::hasColumn('visas', 'country')) {
                $table->string('country')->nullable()->after('country_id');
            }
        });

        Schema::table('excursions', function (Blueprint $table) {
            if (! Schema::hasColumn('excursions', 'country')) {
                $table->string('country')->nullable()->after('group_size');
            }
            if (! Schema::hasColumn('excursions', 'city')) {
                $table->string('city')->nullable()->after('country');
            }
            if (! Schema::hasColumn('excursions', 'location')) {
                $table->string('location')->nullable()->after('offer_id');
            }
        });

        Schema::table('transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('transfers', 'pickup_country')) {
                $table->string('pickup_country', 120)->nullable()->after('transfer_type');
            }
            if (! Schema::hasColumn('transfers', 'pickup_city')) {
                $table->string('pickup_city', 255)->nullable()->after('pickup_country');
            }
            if (! Schema::hasColumn('transfers', 'dropoff_country')) {
                $table->string('dropoff_country', 120)->nullable()->after('pickup_point_name');
            }
            if (! Schema::hasColumn('transfers', 'dropoff_city')) {
                $table->string('dropoff_city', 255)->nullable()->after('dropoff_country');
            }
        });

        Schema::table('flights', function (Blueprint $table) {
            if (! Schema::hasColumn('flights', 'departure_country')) {
                $table->string('departure_country')->nullable()->after('company_id');
            }
            if (! Schema::hasColumn('flights', 'departure_city')) {
                $table->string('departure_city')->nullable()->after('departure_country');
            }
            if (! Schema::hasColumn('flights', 'arrival_country')) {
                $table->string('arrival_country')->nullable()->after('departure_city');
            }
            if (! Schema::hasColumn('flights', 'arrival_city')) {
                $table->string('arrival_city')->nullable()->after('arrival_country');
            }
        });

        Schema::table('cars', function (Blueprint $table) {
            if (! Schema::hasColumn('cars', 'pickup_location')) {
                $table->string('pickup_location')->nullable()->after('location_id');
            }
            if (! Schema::hasColumn('cars', 'dropoff_location')) {
                $table->string('dropoff_location')->nullable()->after('pickup_location');
            }
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropColumnsIfExist(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $drop = [];
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                $drop[] = $column;
            }
        }

        if ($drop === []) {
            return;
        }

        Schema::table($table, function (Blueprint $tableBlueprint) use ($drop): void {
            $tableBlueprint->dropColumn($drop);
        });
    }
};

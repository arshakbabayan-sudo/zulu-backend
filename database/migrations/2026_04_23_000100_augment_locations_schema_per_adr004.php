<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 1 Step 1a — Augment `locations` schema to match ADR-004 target.
 *
 * Brings the minimum-viable `locations` table (id/name/type/parent_id/slug/depth/path)
 * up to the full ADR-004 spec, which lists: code, latitude/longitude, country_code,
 * timezone, metadata (JSONB). Also adds admin-UI fields that used to live on the legacy
 * countries/regions/cities tables: is_active, sort_order, flag_emoji. This is the
 * prerequisite for the AdminLocationController adapter rewrite (Step 1a) and for
 * dropping the legacy tables (Step 1b).
 *
 * Driver-aware:
 *   - `metadata` uses native `jsonb` on PostgreSQL, falls back to `json` on MySQL/SQLite.
 *
 * Safe to rerun in dev because every column add is guarded by `Schema::hasColumn`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('locations', function (Blueprint $table) use ($driver) {
            if (! Schema::hasColumn('locations', 'code')) {
                // ISO-2 for countries, IATA for airports, region/city codes otherwise. Nullable.
                $table->string('code', 16)->nullable()->after('slug');
            }
            if (! Schema::hasColumn('locations', 'country_code')) {
                // Denormalized ISO-2 so "all Armenian hotels" is a single indexed filter.
                $table->string('country_code', 2)->nullable()->after('code');
            }
            if (! Schema::hasColumn('locations', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('country_code');
            }
            if (! Schema::hasColumn('locations', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('is_active');
            }
            if (! Schema::hasColumn('locations', 'flag_emoji')) {
                $table->string('flag_emoji', 16)->nullable()->after('sort_order');
            }
            if (! Schema::hasColumn('locations', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('flag_emoji');
            }
            if (! Schema::hasColumn('locations', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (! Schema::hasColumn('locations', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('longitude');
            }
            if (! Schema::hasColumn('locations', 'metadata')) {
                // JSONB on PG, JSON elsewhere. Blueprint::jsonb() exists only on pgsql.
                $driver === 'pgsql'
                    ? $table->jsonb('metadata')->nullable()->after('timezone')
                    : $table->json('metadata')->nullable()->after('timezone');
            }
        });

        // Indexes — added in a second pass so we can guard hasColumn above without
        // worrying about index-before-column ordering.
        Schema::table('locations', function (Blueprint $table) {
            $table->index(['type', 'country_code'], 'locations_type_country_code_idx');
            $table->index('code', 'locations_code_idx');
            $table->index(['parent_id', 'sort_order'], 'locations_parent_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex('locations_type_country_code_idx');
            $table->dropIndex('locations_code_idx');
            $table->dropIndex('locations_parent_sort_idx');
        });

        Schema::table('locations', function (Blueprint $table) {
            foreach (['metadata', 'timezone', 'longitude', 'latitude', 'flag_emoji', 'sort_order', 'is_active', 'country_code', 'code'] as $col) {
                if (Schema::hasColumn('locations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

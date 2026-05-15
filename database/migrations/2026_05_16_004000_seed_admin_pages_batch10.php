<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13.6 batch 10 — ui_translations EN seeds for the locations admin page:
 *
 *   /platform/locations  (countries / regions / cities CRUD)
 *
 * HY/RU come from translations:scan --ui after deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['admin.locations.title', 'Locations'],
            ['admin.locations.title_long', 'Locations / destinations'],
            ['admin.locations.subtitle', 'GET|POST /api/locations/countries, /countries/{id}/regions, POST /regions, GET /regions/{id}/cities, POST /cities — super admin only'],
            ['admin.locations.section_countries', 'Countries'],
            ['admin.locations.section_regions', 'Regions (country #{id})'],
            ['admin.locations.section_cities', 'Cities (region #{id})'],
            ['admin.locations.field_name', 'Name'],
            ['admin.locations.field_code', 'Code'],
            ['admin.locations.field_flag', 'Flag'],
            ['admin.locations.field_sort', 'Sort'],
            ['admin.locations.field_lat', 'Lat'],
            ['admin.locations.field_lng', 'Lng'],
            ['admin.locations.btn_add_country', 'Add country'],
            ['admin.locations.btn_add_region', 'Add region'],
            ['admin.locations.btn_add_city', 'Add city'],
            ['admin.locations.btn_select', 'Select'],
            ['admin.locations.btn_edit', 'Edit'],
            ['admin.locations.btn_delete', 'Delete'],
            ['admin.locations.col_id', 'ID'],
            ['admin.locations.col_name', 'Name'],
            ['admin.locations.col_code', 'Code'],
            ['admin.locations.col_regions_cities', 'R/C'],
            ['admin.locations.col_cities', 'Cities'],
            ['admin.locations.col_actions', 'Actions'],
            ['admin.locations.prompt_country_name', 'Country name'],
            ['admin.locations.prompt_country_code', 'Country code (2 chars)'],
            ['admin.locations.prompt_region_name', 'Region name'],
            ['admin.locations.prompt_city_name', 'City name'],
            ['admin.locations.confirm_delete_country', 'Delete this country?'],
            ['admin.locations.confirm_delete_region', 'Delete this region?'],
            ['admin.locations.confirm_delete_city', 'Delete this city?'],
            ['admin.locations.err_load_countries', 'Failed to load countries'],
            ['admin.locations.err_load_regions', 'Failed to load regions'],
            ['admin.locations.err_load_cities', 'Failed to load cities'],
            ['admin.locations.err_name_code_required', 'Name and 2-letter code are required.'],
            ['admin.locations.err_code_2_chars', 'Code must be 2 characters.'],
            ['admin.locations.err_create_country', 'Create country failed'],
            ['admin.locations.err_create_region', 'Create region failed'],
            ['admin.locations.err_create_city', 'Create city failed'],
            ['admin.locations.err_update', 'Update failed'],
            ['admin.locations.err_delete', 'Delete failed'],
        ];

        $batch = [];
        foreach ($rows as $r) {
            [$key, $en] = $r;
            $batch[] = ['language_code' => 'en', 'key' => $key, 'value' => $en, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('ui_translations')->upsert(
            $batch,
            ['language_code', 'key'],
            ['value', 'updated_at']
        );

        Cache::forget('ui_translations_en');
    }

    public function down(): void
    {
        // No down() — keys may have been further translated by AI scan.
    }
};

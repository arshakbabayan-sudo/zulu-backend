<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }

        $now = now();
        $defaultConfig = json_encode([
            ['slug' => 'flights', 'label_key' => 'home.search.tab.flights', 'position' => 1, 'is_active' => true],
            ['slug' => 'stays', 'label_key' => 'home.search.tab.stays', 'position' => 2, 'is_active' => true],
            ['slug' => 'packages', 'label_key' => 'home.search.tab.packages', 'position' => 3, 'is_active' => true],
            ['slug' => 'transfer', 'label_key' => 'home.search.tab.transfer', 'position' => 4, 'is_active' => true],
            ['slug' => 'cars', 'label_key' => 'home.search.tab.cars', 'position' => 5, 'is_active' => true],
            ['slug' => 'excursions', 'label_key' => 'home.search.tab.excursions', 'position' => 6, 'is_active' => true],
        ]);

        DB::table('platform_settings')->insertOrIgnore([
            'key' => 'hero_tabs_config',
            'value' => $defaultConfig,
            'type' => 'json',
            'description' => 'Hero search-tab catalog (slug, label_key, position, is_active). Super-admin controls order + visibility; new tabs e.g. charter_jets added by extending this array.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }

        DB::table('platform_settings')->where('key', 'hero_tabs_config')->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Figma 1:18312 (Stays) labels are "Check in" / "Check out" — no hyphen.
     * The DB rows were inserted as "Check-in" / "Check-out", which override
     * the fallback in lib/lang.ts on the storefront and leak into the
     * discovery filter sidebar.
     */
    public function up(): void
    {
        $rows = [
            ['key' => 'home.search.check_in', 'language_code' => 'en', 'value' => 'Check in'],
            ['key' => 'home.search.check_out', 'language_code' => 'en', 'value' => 'Check out'],
            ['key' => 'discovery.filter.check_in', 'language_code' => 'en', 'value' => 'Check in'],
            ['key' => 'discovery.filter.check_out', 'language_code' => 'en', 'value' => 'Check out'],
        ];

        foreach ($rows as $row) {
            DB::table('ui_translations')
                ->where('key', $row['key'])
                ->where('language_code', $row['language_code'])
                ->update(['value' => $row['value'], 'updated_at' => now()]);
        }

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }

    public function down(): void
    {
        $rows = [
            ['key' => 'home.search.check_in', 'language_code' => 'en', 'value' => 'Check-in'],
            ['key' => 'home.search.check_out', 'language_code' => 'en', 'value' => 'Check-out'],
            ['key' => 'discovery.filter.check_in', 'language_code' => 'en', 'value' => 'Check-in'],
            ['key' => 'discovery.filter.check_out', 'language_code' => 'en', 'value' => 'Check-out'],
        ];

        foreach ($rows as $row) {
            DB::table('ui_translations')
                ->where('key', $row['key'])
                ->where('language_code', $row['language_code'])
                ->update(['value' => $row['value'], 'updated_at' => now()]);
        }

        Cache::forget('ui_translations_en');
    }
};

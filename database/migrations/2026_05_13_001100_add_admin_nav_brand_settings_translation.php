<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds the admin.nav.tab.brand_settings key in EN/HY/RU.
     * Used by the new System → Brand sub-tab in the admin sidebar
     * (ZULU CMS Sprint 1 Step 1.3).
     */
    public function up(): void
    {
        $rows = [
            ['key' => 'admin.nav.tab.brand_settings', 'language_code' => 'en', 'value' => 'Brand'],
            ['key' => 'admin.nav.tab.brand_settings', 'language_code' => 'hy', 'value' => 'Բրենդ'],
            ['key' => 'admin.nav.tab.brand_settings', 'language_code' => 'ru', 'value' => 'Бренд'],
        ];

        foreach ($rows as $row) {
            DB::table('ui_translations')->updateOrInsert(
                ['key' => $row['key'], 'language_code' => $row['language_code']],
                ['value' => $row['value'], 'updated_at' => now(), 'created_at' => now()]
            );
        }

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->where('key', 'admin.nav.tab.brand_settings')
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

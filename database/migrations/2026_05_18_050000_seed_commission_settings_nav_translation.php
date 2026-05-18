<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 6B — seeds the `admin.nav.tab.commission_settings` sidebar label
     * in EN/HY/RU. Used by the new /operator/commission-settings page.
     */
    public function up(): void
    {
        $rows = [
            ['admin.nav.tab.commission_settings', 'en', 'Agent commission'],
            ['admin.nav.tab.commission_settings', 'hy', 'Գործակալի կոմիսիոն'],
            ['admin.nav.tab.commission_settings', 'ru', 'Комиссия агента'],
        ];

        foreach ($rows as [$key, $lang, $value]) {
            DB::table('ui_translations')->updateOrInsert(
                ['key' => $key, 'language_code' => $lang],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->where('key', 'admin.nav.tab.commission_settings')
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

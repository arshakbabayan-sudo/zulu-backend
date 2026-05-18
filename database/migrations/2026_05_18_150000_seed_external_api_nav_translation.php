<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 9 — seeds the `admin.nav.tab.external_api` sidebar label for
     * the new /operator/external-api page.
     */
    public function up(): void
    {
        $rows = [
            ['admin.nav.tab.external_api', 'en', 'External API'],
            ['admin.nav.tab.external_api', 'hy', 'Արտաքին API'],
            ['admin.nav.tab.external_api', 'ru', 'Внешнее API'],
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
            ->where('key', 'admin.nav.tab.external_api')
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

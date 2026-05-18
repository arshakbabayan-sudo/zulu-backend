<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5 — seeds the `admin.nav.tab.contracts` sidebar label in EN/HY/RU.
     *
     * Used by /platform/contracts (admin oversight) and /operator/contracts
     * (seller-side) — both surface under the same labelKey, so one row per
     * language is sufficient.
     */
    public function up(): void
    {
        $rows = [
            ['admin.nav.tab.contracts', 'en', 'Contracts'],
            ['admin.nav.tab.contracts', 'hy', 'Պայմանագրեր'],
            ['admin.nav.tab.contracts', 'ru', 'Договоры'],
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
            ->where('key', 'admin.nav.tab.contracts')
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

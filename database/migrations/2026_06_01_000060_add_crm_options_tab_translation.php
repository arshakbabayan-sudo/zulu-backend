<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the CRM "Options" tab label (added 2026-06-01). The page-title resolver
 * reads admin.nav.tab.crm.options with no fallback, so without this row the
 * breadcrumb/title would show the raw key.
 */
return new class extends Migration
{
    public function up(): void
    {
        $byLang = ['en' => 'Options', 'hy' => 'Ընտրանքներ', 'ru' => 'Настройки'];
        $now = now();
        foreach ($byLang as $lang => $value) {
            DB::table('ui_translations')->updateOrInsert(
                ['key' => 'admin.nav.tab.crm.options', 'language_code' => $lang],
                ['value' => $value, 'updated_at' => $now, 'created_at' => $now]
            );
        }
        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->where('key', 'admin.nav.tab.crm.options')
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();
        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

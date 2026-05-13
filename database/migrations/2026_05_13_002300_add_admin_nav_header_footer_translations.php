<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds admin.nav.tab.header_menu + admin.nav.tab.footer in EN/HY/RU.
     * Used by the new System → Header menu + System → Footer sub-tabs
     * (ZULU CMS Sprint 2 Steps 2.3 + 2.4).
     */
    public function up(): void
    {
        $rows = [
            ['key' => 'admin.nav.tab.header_menu', 'language_code' => 'en', 'value' => 'Header menu'],
            ['key' => 'admin.nav.tab.header_menu', 'language_code' => 'hy', 'value' => 'Վերին մենյու'],
            ['key' => 'admin.nav.tab.header_menu', 'language_code' => 'ru', 'value' => 'Верхнее меню'],
            ['key' => 'admin.nav.tab.footer', 'language_code' => 'en', 'value' => 'Footer'],
            ['key' => 'admin.nav.tab.footer', 'language_code' => 'hy', 'value' => 'Ստորին հատված'],
            ['key' => 'admin.nav.tab.footer', 'language_code' => 'ru', 'value' => 'Подвал'],
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
            ->whereIn('key', ['admin.nav.tab.header_menu', 'admin.nav.tab.footer'])
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

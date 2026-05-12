<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['admin.nav.tab.cms_pages', 'en', 'Pages'],
            ['admin.nav.tab.cms_pages', 'hy', 'Կայքի էջեր'],
            ['admin.nav.tab.cms_pages', 'ru', 'Страницы'],
        ];

        foreach ($rows as [$key, $lang, $value]) {
            DB::table('ui_translations')->updateOrInsert(
                ['key' => $key, 'language_code' => $lang],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        DB::table('ui_translations')
            ->where('key', 'like', 'admin.nav.group.cms_pages')
            ->delete();

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->where('key', 'admin.nav.tab.cms_pages')
            ->delete();

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

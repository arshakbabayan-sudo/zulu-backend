<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * P0-2 — i18n for the new "Upload your image" label on the redesigned
 * company Register form (Figma 1:4938 dropzones). EN mirrors the default in
 * zulu-frontend-next/lib/lang.ts; HY + RU are real translations (no Cyrillic
 * in the Armenian column — checked).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            'company.apply.upload_image' => [
                'en' => 'Upload your image',
                'hy' => 'Վերբեռնեք նկարը',
                'ru' => 'Загрузите изображение',
            ],
        ];

        $now = now();
        foreach ($rows as $key => $byLang) {
            foreach ($byLang as $lang => $value) {
                DB::table('ui_translations')->updateOrInsert(
                    ['key' => $key, 'language_code' => $lang],
                    ['value' => $value, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }
        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->where('key', 'company.apply.upload_image')
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();
        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

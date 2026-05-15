<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * UI strings for the AI translator panel that lands on
 * /localization/languages in the admin in Phase 13.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            // key, en, ru, hy
            ['admin.localization.ai_translator_title', 'AI translator', 'AI-переводчик', 'AI թարգմանիչ'],
            ['admin.localization.ai_pending', 'Pending', 'В очереди', 'Հերթում'],
            ['admin.localization.ai_failed', 'Failed', 'Сбоев', 'Ձախողվել է'],
            ['admin.localization.ai_refresh', 'Refresh', 'Обновить', 'Թարմացնել'],
            [
                'admin.localization.ai_description',
                'Walks the localisation tables, finds every key that has a source-language value but is missing in other locales, and queues a Claude AI translation for each gap. The translator stays asleep until you press one of the buttons below.',
                'Сканирует таблицы локализации, находит все ключи, у которых есть значение в исходном языке, но нет переводов на другие, и ставит в очередь задание для AI-переводчика Claude. Переводчик не работает, пока вы не нажмёте одну из кнопок ниже.',
                'Անցնում է թարգմանությունների աղյուսակները, գտնում է բոլոր այն տողերը, որոնք ունեն հիմնական լեզվով արժեք, բայց ուրիշ լեզուներով չունեն, և Claude AI թարգմանիչին հանձնում է դրանք լրացնելու։ Թարգմանիչը քնած է մինչև որևէ կոճակ սեղմես։',
            ],
            ['admin.localization.ai_dry_run', 'Preview gaps (dry run)', 'Предпросмотр (без запуска)', 'Նախադիտում (առանց գործարկման)'],
            ['admin.localization.ai_scan_ui', 'Translate UI strings', 'Перевести UI-строки', 'Թարգմանել UI տեքստերը'],
            ['admin.localization.ai_scan_content', 'Translate content', 'Перевести контент', 'Թարգմանել կոնտենտը'],
            ['admin.localization.ai_scan_both', 'Translate everything', 'Перевести всё', 'Թարգմանել ամեն ինչ'],
        ];

        foreach ($rows as $r) {
            [$key, $en, $ru, $hy] = $r;
            DB::table('ui_translations')->upsert(
                [
                    ['language_code' => 'en', 'key' => $key, 'value' => $en, 'created_at' => $now, 'updated_at' => $now],
                    ['language_code' => 'ru', 'key' => $key, 'value' => $ru, 'created_at' => $now, 'updated_at' => $now],
                    ['language_code' => 'hy', 'key' => $key, 'value' => $hy, 'created_at' => $now, 'updated_at' => $now],
                ],
                ['language_code', 'key'],
                ['value', 'updated_at']
            );
        }

        foreach (['en', 'ru', 'hy'] as $lang) {
            Cache::forget('ui_translations_'.$lang);
        }
    }

    public function down(): void
    {
        $keys = [
            'admin.localization.ai_translator_title',
            'admin.localization.ai_pending',
            'admin.localization.ai_failed',
            'admin.localization.ai_refresh',
            'admin.localization.ai_description',
            'admin.localization.ai_dry_run',
            'admin.localization.ai_scan_ui',
            'admin.localization.ai_scan_content',
            'admin.localization.ai_scan_both',
        ];

        DB::table('ui_translations')->whereIn('key', $keys)->delete();

        foreach (['en', 'ru', 'hy'] as $lang) {
            Cache::forget('ui_translations_'.$lang);
        }
    }
};

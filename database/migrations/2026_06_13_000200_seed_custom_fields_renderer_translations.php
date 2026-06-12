<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap §4 — custom field value storage: the admin CustomFieldsRenderer
 * block (now embedded in all 7 operator inventory forms) needs its three
 * chrome strings in EN/HY/RU. Field labels themselves come from the
 * operator's own definitions and are not translated here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['en', 'admin.crud.custom_fields.section_title', 'Custom fields'],
            ['hy', 'admin.crud.custom_fields.section_title', 'Հատուկ դաշտեր'],
            ['ru', 'admin.crud.custom_fields.section_title', 'Дополнительные поля'],

            ['en', 'admin.crud.custom_fields.loading', 'Loading custom fields…'],
            ['hy', 'admin.crud.custom_fields.loading', 'Հատուկ դաշտերը բեռնվում են…'],
            ['ru', 'admin.crud.custom_fields.loading', 'Загрузка дополнительных полей…'],

            ['en', 'admin.crud.custom_fields.load_failed', 'Failed to load custom fields.'],
            ['hy', 'admin.crud.custom_fields.load_failed', 'Չհաջողվեց բեռնել հատուկ դաշտերը։'],
            ['ru', 'admin.crud.custom_fields.load_failed', 'Не удалось загрузить дополнительные поля.'],
        ];

        $batch = [];
        foreach ($rows as [$lang, $key, $value]) {
            $batch[] = ['language_code' => $lang, 'key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('ui_translations')->upsert(
            $batch,
            ['language_code', 'key'],
            ['value', 'updated_at']
        );

        foreach (['en', 'hy', 'ru'] as $lang) {
            Cache::forget("ui_translations_{$lang}");
        }
    }

    public function down(): void
    {
        // Translations may be refined in the admin UI afterwards — keep.
    }
};

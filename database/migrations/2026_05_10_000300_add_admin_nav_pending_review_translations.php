<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds the admin.nav.pending_review key in EN/HY/RU. The admin sidebar
     * already references this key (see ADMIN_PLATFORM_LINKS); without the
     * row, the UI rendered the raw `admin.nav.pending_review` string.
     *
     * Cache::rememberForever caches translations per-language under
     * `ui_translations_<lang>`, so we forget the three entries after the
     * upsert (per the standard ZULU translation workflow).
     */
    public function up(): void
    {
        $rows = [
            ['key' => 'admin.nav.pending_review', 'language_code' => 'en', 'value' => 'Pending review'],
            ['key' => 'admin.nav.pending_review', 'language_code' => 'hy', 'value' => 'Սպասում է վերանայման'],
            ['key' => 'admin.nav.pending_review', 'language_code' => 'ru', 'value' => 'Ожидает проверки'],
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
            ->where('key', 'admin.nav.pending_review')
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

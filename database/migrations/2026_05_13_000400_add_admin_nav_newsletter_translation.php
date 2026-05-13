<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds the admin.nav.tab.newsletter key in EN/HY/RU. Used by the new
     * Content → Newsletter sub-tab in admin sidebar (Phase 3 Step 3.6).
     */
    public function up(): void
    {
        $rows = [
            ['key' => 'admin.nav.tab.newsletter', 'language_code' => 'en', 'value' => 'Newsletter'],
            ['key' => 'admin.nav.tab.newsletter', 'language_code' => 'hy', 'value' => 'Տեղեկագիր'],
            ['key' => 'admin.nav.tab.newsletter', 'language_code' => 'ru', 'value' => 'Рассылка'],
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
            ->where('key', 'admin.nav.tab.newsletter')
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the Chat sidebar label (added 2026-06-01). The sidebar has a "Chat"
 * labelFallback baked into admin-nav-config, but seed the real key so it
 * renders in the user's language (Armenian priority).
 */
return new class extends Migration
{
    public function up(): void
    {
        $byLang = ['en' => 'Chat', 'hy' => 'Չաթ', 'ru' => 'Чат'];
        $now = now();
        foreach ($byLang as $lang => $value) {
            DB::table('ui_translations')->updateOrInsert(
                ['key' => 'admin.nav.section.chat', 'language_code' => $lang],
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
            ->where('key', 'admin.nav.section.chat')
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();
        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

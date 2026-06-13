<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap §4 — the support-chat widget close button used a hardcoded English
 * aria-label. Seed the missing chat.widget.close key so screen-reader users
 * hear it in their own language.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['close', 'Close', 'Փակել', 'Закрыть'],
        ];

        $batch = [];
        foreach ($rows as [$suffix, $en, $hy, $ru]) {
            foreach (['en' => $en, 'hy' => $hy, 'ru' => $ru] as $lang => $value) {
                $batch[] = [
                    'language_code' => $lang,
                    'key' => 'chat.widget.'.$suffix,
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
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
        // Translations may be refined afterwards — keep.
    }
};

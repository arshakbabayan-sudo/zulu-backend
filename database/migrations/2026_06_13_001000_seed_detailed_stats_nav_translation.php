<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap §11 — the dashboard "Detailed stats" button (now shown to operator-
 * admins too) used an unseeded key, leaking English on the Armenian UI. Seed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['admin.nav.tab.detailed_stats', 'Detailed stats', 'Մանրամասն վիճակագրություն', 'Подробная статистика'],
        ];

        $batch = [];
        foreach ($rows as [$key, $en, $hy, $ru]) {
            foreach (['en' => $en, 'hy' => $hy, 'ru' => $ru] as $lang => $value) {
                $batch[] = [
                    'language_code' => $lang,
                    'key' => $key,
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

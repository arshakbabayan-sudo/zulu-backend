<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap §1/§4 — the External API page got a tab in the operator
 * inventory strip (it was an orphan page nothing linked to); seed its label.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['en', 'admin.nav.tab.external_api', 'External API'],
            ['hy', 'admin.nav.tab.external_api', 'Արտաքին API'],
            ['ru', 'admin.nav.tab.external_api', 'Внешний API'],
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

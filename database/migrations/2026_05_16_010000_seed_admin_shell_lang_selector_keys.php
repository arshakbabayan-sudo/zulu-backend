<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase A.1 — admin shell now exposes TWO language selectors:
 *   1. UI language    — controls chrome (buttons, sidebar, menus)
 *   2. Content language — controls catalog data fetched (hotel names, descriptions, etc.)
 *
 * These keys label the second selector so admins can distinguish the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['admin.shell.ui_language', 'UI language'],
            ['admin.shell.content_language', 'Content preview language'],
        ];

        $batch = [];
        foreach ($rows as $r) {
            [$key, $en] = $r;
            $batch[] = ['language_code' => 'en', 'key' => $key, 'value' => $en, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('ui_translations')->upsert(
            $batch,
            ['language_code', 'key'],
            ['value', 'updated_at']
        );

        Cache::forget('ui_translations_en');
    }

    public function down(): void
    {
        // No down() — AI scan may translate further.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase A.1 follow-up — UI label for the ContentLanguagePill component shown
 * on operator catalog pages (Hotels, Cars, Excursions, Transfers, Visas,
 * Packages, Flights, Offers).
 *
 * Supersedes the deleted admin.shell.ui_language / admin.shell.content_language
 * pair from migration 2026_05_16_010000 — those keys are still in the table but
 * unreferenced after the AdminShell revert; safe to leave (no harm) or prune
 * later.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['admin.content_lang_pill.label', 'Show content as'],
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

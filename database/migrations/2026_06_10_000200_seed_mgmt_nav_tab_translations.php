<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap 10.06 §6 — seed the Management nav-tab labels that were still
 * falling through to their English labelFallback (admin.nav.tab.
 * b2c_customers / unverified_accounts from the 2026-06-04 Directory fold-in,
 * plus pending_review surfaced in §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['en', 'admin.nav.tab.b2c_customers', 'B2C customers'],
            ['hy', 'admin.nav.tab.b2c_customers', 'B2C հաճախորդներ'],
            ['ru', 'admin.nav.tab.b2c_customers', 'B2C клиенты'],

            ['en', 'admin.nav.tab.unverified_accounts', 'Unverified accounts'],
            ['hy', 'admin.nav.tab.unverified_accounts', 'Չհաստատված հաշիվներ'],
            ['ru', 'admin.nav.tab.unverified_accounts', 'Неподтверждённые аккаунты'],

            ['en', 'admin.nav.tab.pending_review', 'Pending review'],
            ['hy', 'admin.nav.tab.pending_review', 'Ստուգման հերթ'],
            ['ru', 'admin.nav.tab.pending_review', 'Очередь модерации'],
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Seeds EN/HY/RU translations for the new CRM sidebar section + its tab keys.
 *
 * The CRM section (2026-05-31) is a separate top-level sidebar entry with
 * tabs Pipeline / Leads / Deals / Customers / Activities / Segments / Team.
 * The group has a labelFallback ("CRM") baked into admin-nav-config.ts, but
 * the per-tab keys are read by resolveAdminPageTitle() with NO fallback —
 * so without these rows the top-bar page title would show the raw key
 * (e.g. "admin.nav.tab.crm.leads"). Armenian is the priority.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            'admin.nav.section.crm'         => ['en' => 'CRM',         'hy' => 'CRM',                    'ru' => 'CRM'],
            'admin.nav.tab.crm.pipeline'   => ['en' => 'Pipeline',    'hy' => 'Հոսք',                  'ru' => 'Воронка'],
            'admin.nav.tab.crm.leads'      => ['en' => 'Leads',       'hy' => 'Հնարավոր հաճախորդներ',   'ru' => 'Лиды'],
            'admin.nav.tab.crm.deals'      => ['en' => 'Deals',       'hy' => 'Գործարքներ',            'ru' => 'Сделки'],
            'admin.nav.tab.crm.customers'  => ['en' => 'Customers',   'hy' => 'Հաճախորդներ',           'ru' => 'Клиенты'],
            'admin.nav.tab.crm.activities' => ['en' => 'Activities',  'hy' => 'Գործողություններ',       'ru' => 'Действия'],
            'admin.nav.tab.crm.segments'   => ['en' => 'Segments',    'hy' => 'Սեգմենտներ',            'ru' => 'Сегменты'],
            'admin.nav.tab.crm.team'       => ['en' => 'Team',        'hy' => 'Թիմ',                   'ru' => 'Команда'],
        ];

        $now = now();
        foreach ($rows as $key => $byLang) {
            foreach ($byLang as $lang => $value) {
                DB::table('ui_translations')->updateOrInsert(
                    ['key' => $key, 'language_code' => $lang],
                    ['value' => $value, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }

    public function down(): void
    {
        $keys = [
            'admin.nav.section.crm',
            'admin.nav.tab.crm.pipeline',
            'admin.nav.tab.crm.leads',
            'admin.nav.tab.crm.deals',
            'admin.nav.tab.crm.customers',
            'admin.nav.tab.crm.activities',
            'admin.nav.tab.crm.segments',
            'admin.nav.tab.crm.team',
        ];

        DB::table('ui_translations')
            ->whereIn('key', $keys)
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

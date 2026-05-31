<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Seeds EN/HY/RU translations for the admin sidebar's section labels and the
 * new Directory/Inbox tab keys.
 *
 * Background: the 8-section IA (2026-05-24) switched the sidebar from the
 * old `admin.nav.group.*` keys to `admin.nav.section.*`, but those new keys
 * were never seeded — the sidebar has been falling back to the English
 * `labelFallback` baked into admin-nav-config.ts ever since. The 2026-05-31
 * menu restructure then added Directory / HR / Inbox / Management sections
 * plus directory.people / directory.companies / my_notifications tabs, whose
 * keys leaked RAW (e.g. the breadcrumb showed "admin.nav.tab.directory.people"
 * because the page-title resolver calls t() without a fallback).
 *
 * This migration seeds all of them so the sidebar + breadcrumbs render in the
 * user's language. Armenian is the priority (product owner is Armenian-first).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            // ── Section labels (12 top-level sidebar items) ──────────────
            'admin.nav.section.dashboard'     => ['en' => 'Dashboard',     'hy' => 'Վահանակ',           'ru' => 'Панель'],
            'admin.nav.section.inventory'     => ['en' => 'Inventory',     'hy' => 'Գույքագրում',       'ru' => 'Инвентарь'],
            'admin.nav.section.bookings'      => ['en' => 'Bookings',      'hy' => 'Ամրագրումներ',      'ru' => 'Брони'],
            'admin.nav.section.finance'       => ['en' => 'Finance',       'hy' => 'Ֆինանսներ',         'ru' => 'Финансы'],
            'admin.nav.section.my_company'    => ['en' => 'My company',    'hy' => 'Իմ ընկերությունը',  'ru' => 'Моя компания'],
            'admin.nav.section.hr'            => ['en' => 'HR',            'hy' => 'Կադրեր',            'ru' => 'Кадры'],
            'admin.nav.section.management'    => ['en' => 'Management',    'hy' => 'Կառավարում',        'ru' => 'Управление'],
            'admin.nav.section.directory'     => ['en' => 'Directory',     'hy' => 'Տեղեկագիրք',        'ru' => 'Справочник'],
            'admin.nav.section.file_manager'  => ['en' => 'File manager',  'hy' => 'Ֆայլեր',            'ru' => 'Файлы'],
            'admin.nav.section.my_profile'    => ['en' => 'My profile',    'hy' => 'Իմ պրոֆիլը',        'ru' => 'Мой профиль'],
            'admin.nav.section.inbox'         => ['en' => 'Inbox',         'hy' => 'Մուտքային',         'ru' => 'Входящие'],
            'admin.nav.section.settings'      => ['en' => 'Settings',      'hy' => 'Կարգավորումներ',    'ru' => 'Настройки'],

            // ── New tab keys introduced 2026-05-31 ───────────────────────
            'admin.nav.tab.directory.people'    => ['en' => 'People',          'hy' => 'Մարդիկ',            'ru' => 'Люди'],
            'admin.nav.tab.directory.companies' => ['en' => 'Companies',       'hy' => 'Ընկերություններ',   'ru' => 'Компании'],
            'admin.nav.tab.my_notifications'    => ['en' => 'My notifications', 'hy' => 'Իմ ծանուցումները', 'ru' => 'Мои уведомления'],
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
            'admin.nav.section.dashboard',
            'admin.nav.section.inventory',
            'admin.nav.section.bookings',
            'admin.nav.section.finance',
            'admin.nav.section.my_company',
            'admin.nav.section.hr',
            'admin.nav.section.management',
            'admin.nav.section.directory',
            'admin.nav.section.file_manager',
            'admin.nav.section.my_profile',
            'admin.nav.section.inbox',
            'admin.nav.section.settings',
            'admin.nav.tab.directory.people',
            'admin.nav.tab.directory.companies',
            'admin.nav.tab.my_notifications',
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

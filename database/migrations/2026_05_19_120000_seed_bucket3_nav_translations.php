<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 — seed bucket3 nav translations.
 *
 * Phase 7 added 15 Bucket-3 admin modules behind /bucket3/* routes and wired
 * them into admin-nav-config.ts, but the group label + 15 tab label keys
 * were never seeded into ui_translations. Without these rows the sidebar
 * renders raw keys like "admin.nav.tab.bucket3.payroll". This migration
 * fills the gap for EN/HY/RU.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            // Group label
            ['admin.nav.group.bucket3', 'en', 'Operations & Workforce'],
            ['admin.nav.group.bucket3', 'hy', 'Գործառնություններ և անձնակազմ'],
            ['admin.nav.group.bucket3', 'ru', 'Операции и персонал'],

            // 15 tab labels
            ['admin.nav.tab.bucket3.customers', 'en', 'Customers (B2C)'],
            ['admin.nav.tab.bucket3.customers', 'hy', 'Հաճախորդներ (B2C)'],
            ['admin.nav.tab.bucket3.customers', 'ru', 'Клиенты (B2C)'],

            ['admin.nav.tab.bucket3.block_dates', 'en', 'Block dates'],
            ['admin.nav.tab.bucket3.block_dates', 'hy', 'Փակել ամսաթվեր'],
            ['admin.nav.tab.bucket3.block_dates', 'ru', 'Блокировка дат'],

            ['admin.nav.tab.bucket3.per_x_invoicing', 'en', 'Per-X invoicing'],
            ['admin.nav.tab.bucket3.per_x_invoicing', 'hy', 'Per-X հաշիվներ'],
            ['admin.nav.tab.bucket3.per_x_invoicing', 'ru', 'Per-X-инвойсы'],

            ['admin.nav.tab.bucket3.custom_fields', 'en', 'Custom fields'],
            ['admin.nav.tab.bucket3.custom_fields', 'hy', 'Հատուկ դաշտեր'],
            ['admin.nav.tab.bucket3.custom_fields', 'ru', 'Кастомные поля'],

            ['admin.nav.tab.bucket3.employees', 'en', 'Employees'],
            ['admin.nav.tab.bucket3.employees', 'hy', 'Աշխատակիցներ'],
            ['admin.nav.tab.bucket3.employees', 'ru', 'Сотрудники'],

            ['admin.nav.tab.bucket3.bulk_notifications', 'en', 'Bulk notifications'],
            ['admin.nav.tab.bucket3.bulk_notifications', 'hy', 'Զանգվածային ծանուցումներ'],
            ['admin.nav.tab.bucket3.bulk_notifications', 'ru', 'Массовые уведомления'],

            ['admin.nav.tab.bucket3.requests', 'en', 'Requests'],
            ['admin.nav.tab.bucket3.requests', 'hy', 'Հարցումներ'],
            ['admin.nav.tab.bucket3.requests', 'ru', 'Запросы'],

            ['admin.nav.tab.bucket3.unverified_accounts', 'en', 'Unverified accounts'],
            ['admin.nav.tab.bucket3.unverified_accounts', 'hy', 'Չհաստատված հաշիվներ'],
            ['admin.nav.tab.bucket3.unverified_accounts', 'ru', 'Неподтверждённые аккаунты'],

            ['admin.nav.tab.bucket3.service_logs', 'en', 'Service logs'],
            ['admin.nav.tab.bucket3.service_logs', 'hy', 'Ծառայության մատյան'],
            ['admin.nav.tab.bucket3.service_logs', 'ru', 'Журнал услуг'],

            ['admin.nav.tab.bucket3.cases', 'en', 'Cases'],
            ['admin.nav.tab.bucket3.cases', 'hy', 'Գործեր'],
            ['admin.nav.tab.bucket3.cases', 'ru', 'Кейсы'],

            ['admin.nav.tab.bucket3.subscriptions', 'en', 'Subscriptions'],
            ['admin.nav.tab.bucket3.subscriptions', 'hy', 'Բաժանորդագրություններ'],
            ['admin.nav.tab.bucket3.subscriptions', 'ru', 'Подписки'],

            ['admin.nav.tab.bucket3.service_catalog', 'en', 'Service catalog'],
            ['admin.nav.tab.bucket3.service_catalog', 'hy', 'Ծառայությունների կատալոգ'],
            ['admin.nav.tab.bucket3.service_catalog', 'ru', 'Каталог услуг'],

            ['admin.nav.tab.bucket3.non_service_hours', 'en', 'Non-service hours'],
            ['admin.nav.tab.bucket3.non_service_hours', 'hy', 'Ոչ ծառայության ժամեր'],
            ['admin.nav.tab.bucket3.non_service_hours', 'ru', 'Внерабочие часы'],

            ['admin.nav.tab.bucket3.pin_settings', 'en', 'PIN settings'],
            ['admin.nav.tab.bucket3.pin_settings', 'hy', 'PIN-ի կարգավորումներ'],
            ['admin.nav.tab.bucket3.pin_settings', 'ru', 'Настройки PIN'],

            ['admin.nav.tab.bucket3.payroll', 'en', 'Payroll'],
            ['admin.nav.tab.bucket3.payroll', 'hy', 'Աշխատավարձ'],
            ['admin.nav.tab.bucket3.payroll', 'ru', 'Зарплата'],
        ];

        foreach ($rows as [$key, $lang, $value]) {
            DB::table('ui_translations')->updateOrInsert(
                ['key' => $key, 'language_code' => $lang],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->where(function ($q) {
                $q->where('key', '=', 'admin.nav.group.bucket3')
                    ->orWhere('key', 'like', 'admin.nav.tab.bucket3.%');
            })
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

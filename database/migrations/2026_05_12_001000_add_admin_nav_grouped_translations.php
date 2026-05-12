<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds the EN/HY/RU translations for the new grouped-admin-nav model:
     *   - 12 group labels  (admin.nav.group.*)
     *   - ~35 tab labels   (admin.nav.tab.*)
     *
     * Without these rows the sidebar renders the raw key string. The
     * Cache::rememberForever entries for ui_translations_{en,hy,ru} are
     * forgotten at the end so the next page load picks up the rows.
     */
    public function up(): void
    {
        $rows = [
            // ── Group labels ──────────────────────────────────────────────
            ['admin.nav.group.companies_access', 'en', 'Companies & Access'],
            ['admin.nav.group.companies_access', 'hy', 'Ընկերություններ և մուտքեր'],
            ['admin.nav.group.companies_access', 'ru', 'Компании и доступы'],

            ['admin.nav.group.reviews_approvals', 'en', 'Reviews & Approvals'],
            ['admin.nav.group.reviews_approvals', 'hy', 'Վերանայումներ և հաստատումներ'],
            ['admin.nav.group.reviews_approvals', 'ru', 'Проверки и согласования'],

            ['admin.nav.group.inventory_oversight', 'en', 'Inventory oversight'],
            ['admin.nav.group.inventory_oversight', 'hy', 'Համացանցի վերահսկում'],
            ['admin.nav.group.inventory_oversight', 'ru', 'Контроль инвентаря'],

            ['admin.nav.group.my_inventory', 'en', 'My inventory'],
            ['admin.nav.group.my_inventory', 'hy', 'Իմ բաժինը'],
            ['admin.nav.group.my_inventory', 'ru', 'Мой инвентарь'],

            ['admin.nav.group.bookings', 'en', 'Bookings'],
            ['admin.nav.group.bookings', 'hy', 'Ամրագրումներ'],
            ['admin.nav.group.bookings', 'ru', 'Бронирования'],

            ['admin.nav.group.finance', 'en', 'Finance'],
            ['admin.nav.group.finance', 'hy', 'Ֆինանսներ'],
            ['admin.nav.group.finance', 'ru', 'Финансы'],

            ['admin.nav.group.content', 'en', 'Content'],
            ['admin.nav.group.content', 'hy', 'Բովանդակություն'],
            ['admin.nav.group.content', 'ru', 'Контент'],

            ['admin.nav.group.cms_pages', 'en', 'Pages'],
            ['admin.nav.group.cms_pages', 'hy', 'Կայքի էջեր'],
            ['admin.nav.group.cms_pages', 'ru', 'Страницы'],

            ['admin.nav.group.localization', 'en', 'Localization'],
            ['admin.nav.group.localization', 'hy', 'Տեղայնացում'],
            ['admin.nav.group.localization', 'ru', 'Локализация'],

            ['admin.nav.group.operations', 'en', 'Operations'],
            ['admin.nav.group.operations', 'hy', 'Գործառնություններ'],
            ['admin.nav.group.operations', 'ru', 'Операции'],

            ['admin.nav.group.loyalty_promo', 'en', 'Loyalty & Promo'],
            ['admin.nav.group.loyalty_promo', 'hy', 'Հավատարմություն և ակցիա'],
            ['admin.nav.group.loyalty_promo', 'ru', 'Лояльность и акции'],

            ['admin.nav.group.system', 'en', 'System'],
            ['admin.nav.group.system', 'hy', 'Համակարգ'],
            ['admin.nav.group.system', 'ru', 'Система'],

            // ── Tab labels — Companies & Access ───────────────────────────
            ['admin.nav.tab.active_companies', 'en', 'Companies'],
            ['admin.nav.tab.active_companies', 'hy', 'Ընկերություններ'],
            ['admin.nav.tab.active_companies', 'ru', 'Компании'],

            ['admin.nav.tab.applications', 'en', 'Applications'],
            ['admin.nav.tab.applications', 'hy', 'Դիմումներ'],
            ['admin.nav.tab.applications', 'ru', 'Заявки'],

            ['admin.nav.tab.seller_applications', 'en', 'Seller applications'],
            ['admin.nav.tab.seller_applications', 'hy', 'Վաճառողի դիմումներ'],
            ['admin.nav.tab.seller_applications', 'ru', 'Заявки продавцов'],

            ['admin.nav.tab.users', 'en', 'Users'],
            ['admin.nav.tab.users', 'hy', 'Օգտատերեր'],
            ['admin.nav.tab.users', 'ru', 'Пользователи'],

            // ── Tab labels — Reviews & Approvals ──────────────────────────
            ['admin.nav.tab.pending_offers', 'en', 'Pending offers'],
            ['admin.nav.tab.pending_offers', 'hy', 'Սպասվող առաջարկներ'],
            ['admin.nav.tab.pending_offers', 'ru', 'Ожидающие предложения'],

            ['admin.nav.tab.generic_approvals', 'en', 'Generic approvals'],
            ['admin.nav.tab.generic_approvals', 'hy', 'Ընդհանուր հաստատումներ'],
            ['admin.nav.tab.generic_approvals', 'ru', 'Общие согласования'],

            // ── Tab labels — Inventory / My inventory ─────────────────────
            ['admin.nav.tab.flights', 'en', 'Flights'],
            ['admin.nav.tab.flights', 'hy', 'Թռիչքներ'],
            ['admin.nav.tab.flights', 'ru', 'Авиабилеты'],

            ['admin.nav.tab.hotels', 'en', 'Hotels'],
            ['admin.nav.tab.hotels', 'hy', 'Հյուրանոցներ'],
            ['admin.nav.tab.hotels', 'ru', 'Отели'],

            ['admin.nav.tab.transfers', 'en', 'Transfers'],
            ['admin.nav.tab.transfers', 'hy', 'Փոխադրումներ'],
            ['admin.nav.tab.transfers', 'ru', 'Трансферы'],

            ['admin.nav.tab.cars', 'en', 'Cars'],
            ['admin.nav.tab.cars', 'hy', 'Մեքենաներ'],
            ['admin.nav.tab.cars', 'ru', 'Авто'],

            ['admin.nav.tab.excursions', 'en', 'Excursions'],
            ['admin.nav.tab.excursions', 'hy', 'Էքսկուրսիաներ'],
            ['admin.nav.tab.excursions', 'ru', 'Экскурсии'],

            ['admin.nav.tab.visas', 'en', 'Visas'],
            ['admin.nav.tab.visas', 'hy', 'Վիզաներ'],
            ['admin.nav.tab.visas', 'ru', 'Визы'],

            ['admin.nav.tab.packages', 'en', 'Packages'],
            ['admin.nav.tab.packages', 'hy', 'Փաթեթներ'],
            ['admin.nav.tab.packages', 'ru', 'Пакеты'],

            ['admin.nav.tab.offers', 'en', 'Offers'],
            ['admin.nav.tab.offers', 'hy', 'Առաջարկներ'],
            ['admin.nav.tab.offers', 'ru', 'Предложения'],

            // ── Tab labels — Bookings ─────────────────────────────────────
            ['admin.nav.tab.all_bookings', 'en', 'All bookings'],
            ['admin.nav.tab.all_bookings', 'hy', 'Բոլոր ամրագրումները'],
            ['admin.nav.tab.all_bookings', 'ru', 'Все бронирования'],

            ['admin.nav.tab.package_orders', 'en', 'Package orders'],
            ['admin.nav.tab.package_orders', 'hy', 'Փաթեթի պատվերներ'],
            ['admin.nav.tab.package_orders', 'ru', 'Заказы пакетов'],

            // ── Tab labels — Finance ──────────────────────────────────────
            ['admin.nav.tab.finance_summary', 'en', 'Summary'],
            ['admin.nav.tab.finance_summary', 'hy', 'Ամփոփ'],
            ['admin.nav.tab.finance_summary', 'ru', 'Сводка'],

            ['admin.nav.tab.invoices', 'en', 'Invoices'],
            ['admin.nav.tab.invoices', 'hy', 'Հաշիվ-ապրանքագրեր'],
            ['admin.nav.tab.invoices', 'ru', 'Счета'],

            ['admin.nav.tab.payments', 'en', 'Payments'],
            ['admin.nav.tab.payments', 'hy', 'Վճարումներ'],
            ['admin.nav.tab.payments', 'ru', 'Платежи'],

            ['admin.nav.tab.commissions', 'en', 'Commissions'],
            ['admin.nav.tab.commissions', 'hy', 'Միջնորդավճարներ'],
            ['admin.nav.tab.commissions', 'ru', 'Комиссии'],

            ['admin.nav.tab.transactions', 'en', 'Transactions'],
            ['admin.nav.tab.transactions', 'hy', 'Գործարքներ'],
            ['admin.nav.tab.transactions', 'ru', 'Транзакции'],

            // ── Tab labels — Content ──────────────────────────────────────
            ['admin.nav.tab.banners', 'en', 'Banners'],
            ['admin.nav.tab.banners', 'hy', 'Բաններներ'],
            ['admin.nav.tab.banners', 'ru', 'Баннеры'],

            ['admin.nav.tab.system_notifications', 'en', 'System notifications'],
            ['admin.nav.tab.system_notifications', 'hy', 'Համակարգային ծանուցումներ'],
            ['admin.nav.tab.system_notifications', 'ru', 'Системные уведомления'],

            ['admin.nav.tab.email_templates', 'en', 'Email templates'],
            ['admin.nav.tab.email_templates', 'hy', 'Էլ. փոստի կաղապարներ'],
            ['admin.nav.tab.email_templates', 'ru', 'Email-шаблоны'],

            // ── Tab labels — Localization ─────────────────────────────────
            ['admin.nav.tab.ui_strings', 'en', 'UI strings'],
            ['admin.nav.tab.ui_strings', 'hy', 'UI տեքստեր'],
            ['admin.nav.tab.ui_strings', 'ru', 'UI-строки'],

            ['admin.nav.tab.content_translations', 'en', 'Content translations'],
            ['admin.nav.tab.content_translations', 'hy', 'Բովանդակային թարգմանություններ'],
            ['admin.nav.tab.content_translations', 'ru', 'Переводы контента'],

            ['admin.nav.tab.languages', 'en', 'Languages'],
            ['admin.nav.tab.languages', 'hy', 'Լեզուներ'],
            ['admin.nav.tab.languages', 'ru', 'Языки'],

            // ── Tab labels — Operations ───────────────────────────────────
            ['admin.nav.tab.connections', 'en', 'Connections'],
            ['admin.nav.tab.connections', 'hy', 'Միացումներ'],
            ['admin.nav.tab.connections', 'ru', 'Подключения'],

            ['admin.nav.tab.support', 'en', 'Support'],
            ['admin.nav.tab.support', 'hy', 'Աջակցություն'],
            ['admin.nav.tab.support', 'ru', 'Поддержка'],

            ['admin.nav.tab.reviews', 'en', 'Reviews'],
            ['admin.nav.tab.reviews', 'hy', 'Կարծիքներ'],
            ['admin.nav.tab.reviews', 'ru', 'Отзывы'],

            ['admin.nav.tab.statistics', 'en', 'Statistics'],
            ['admin.nav.tab.statistics', 'hy', 'Վիճակագրություն'],
            ['admin.nav.tab.statistics', 'ru', 'Статистика'],

            // ── Tab labels — Loyalty ──────────────────────────────────────
            ['admin.nav.tab.loyalty_programs', 'en', 'Loyalty programs'],
            ['admin.nav.tab.loyalty_programs', 'hy', 'Հավատարմության ծրագրեր'],
            ['admin.nav.tab.loyalty_programs', 'ru', 'Программы лояльности'],

            // ── Tab labels — System ───────────────────────────────────────
            ['admin.nav.tab.rbac', 'en', 'Roles & Permissions'],
            ['admin.nav.tab.rbac', 'hy', 'Դերեր և թույլտվություններ'],
            ['admin.nav.tab.rbac', 'ru', 'Роли и права'],

            ['admin.nav.tab.security', 'en', 'Security'],
            ['admin.nav.tab.security', 'hy', 'Անվտանգություն'],
            ['admin.nav.tab.security', 'ru', 'Безопасность'],

            ['admin.nav.tab.webhooks', 'en', 'Webhooks'],
            ['admin.nav.tab.webhooks', 'hy', 'Webhook-ներ'],
            ['admin.nav.tab.webhooks', 'ru', 'Webhooks'],

            ['admin.nav.tab.locations', 'en', 'Locations'],
            ['admin.nav.tab.locations', 'hy', 'Տեղանքներ'],
            ['admin.nav.tab.locations', 'ru', 'Локации'],

            ['admin.nav.tab.audit_logs', 'en', 'Audit logs'],
            ['admin.nav.tab.audit_logs', 'hy', 'Աուդիտի մատյան'],
            ['admin.nav.tab.audit_logs', 'ru', 'Журнал аудита'],

            ['admin.nav.tab.api_docs', 'en', 'API documentation'],
            ['admin.nav.tab.api_docs', 'hy', 'API փաստաթղթեր'],
            ['admin.nav.tab.api_docs', 'ru', 'Документация API'],
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
                $q->where('key', 'like', 'admin.nav.group.%')
                    ->orWhere('key', 'like', 'admin.nav.tab.%');
            })
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

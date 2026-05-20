<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the 41 UI translation keys that `t("…")` calls reference in the
 * code but were missing from the prod DB entirely.
 *
 * Discovered by the G1 translation completeness audit on 2026-05-20:
 * the Node script in docs/audits/translation-coverage-2026-05-20.md
 * extracted every t() key used across the admin + frontend code (2491
 * unique) and compared against the live /api/localization/ui-translations
 * endpoint (3294 keys per lang). 41 keys were used but never seeded,
 * which means HY and RU users were seeing the raw key string (e.g.
 * "account.stats.title") instead of localized text on the Stats page,
 * the Notifications page header buttons, several admin empty-states,
 * and the User-detail form.
 *
 * After deploy remember to clear the ui_translations cache for all 3
 * languages so the new rows propagate immediately:
 *   php artisan cache:forget ui_translations_en
 *   php artisan cache:forget ui_translations_hy
 *   php artisan cache:forget ui_translations_ru
 *
 * Roadmap ref: G1 in docs/roadmaps/zulu-roadmap-2026-05-20.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            // ── Account → Notifications page actions ─────────────────────
            ['account.notifications.mark_all_read', 'en', 'Mark all as read'],
            ['account.notifications.mark_all_read', 'hy', 'Նշել բոլորը որպես կարդացված'],
            ['account.notifications.mark_all_read', 'ru', 'Отметить всё как прочитанное'],

            ['account.notifications.mark_read', 'en', 'Mark as read'],
            ['account.notifications.mark_read', 'hy', 'Նշել որպես կարդացված'],
            ['account.notifications.mark_read', 'ru', 'Отметить как прочитанное'],

            ['account.notifications.marking', 'en', 'Marking…'],
            ['account.notifications.marking', 'hy', 'Նշվում է…'],
            ['account.notifications.marking', 'ru', 'Отмечается…'],

            // ── Account → Stats page (entirely new section) ──────────────
            ['account.stats.title', 'en', 'My statistics'],
            ['account.stats.title', 'hy', 'Իմ վիճակագրությունը'],
            ['account.stats.title', 'ru', 'Моя статистика'],

            ['account.stats.subtitle', 'en', 'A quick look at your activity, spending, and loyalty status.'],
            ['account.stats.subtitle', 'hy', 'Արագ ակնարկ՝ ձեր ակտիվությանը, ծախսերին ու հավատարմության կարգավիճակին։'],
            ['account.stats.subtitle', 'ru', 'Краткий обзор активности, расходов и статуса лояльности.'],

            ['account.stats.activity', 'en', 'Activity'],
            ['account.stats.activity', 'hy', 'Ակտիվություն'],
            ['account.stats.activity', 'ru', 'Активность'],

            ['account.stats.orders', 'en', 'Orders'],
            ['account.stats.orders', 'hy', 'Պատվերներ'],
            ['account.stats.orders', 'ru', 'Заказы'],

            ['account.stats.paid_orders', 'en', 'Paid orders'],
            ['account.stats.paid_orders', 'hy', 'Վճարված պատվերներ'],
            ['account.stats.paid_orders', 'ru', 'Оплаченные заказы'],

            ['account.stats.total_spent', 'en', 'Total spent'],
            ['account.stats.total_spent', 'hy', 'Ընդամենը ծախսվել է'],
            ['account.stats.total_spent', 'ru', 'Всего потрачено'],

            ['account.stats.avg_order', 'en', 'Average order'],
            ['account.stats.avg_order', 'hy', 'Միջին պատվեր'],
            ['account.stats.avg_order', 'ru', 'Средний заказ'],

            ['account.stats.first_order', 'en', 'First order'],
            ['account.stats.first_order', 'hy', 'Առաջին պատվեր'],
            ['account.stats.first_order', 'ru', 'Первый заказ'],

            ['account.stats.last_order', 'en', 'Last order'],
            ['account.stats.last_order', 'hy', 'Վերջին պատվեր'],
            ['account.stats.last_order', 'ru', 'Последний заказ'],

            ['account.stats.last_30_days', 'en', 'Last 30 days'],
            ['account.stats.last_30_days', 'hy', 'Վերջին 30 օրը'],
            ['account.stats.last_30_days', 'ru', 'Последние 30 дней'],

            ['account.stats.lifetime', 'en', 'Lifetime'],
            ['account.stats.lifetime', 'hy', 'Ամբողջ ժամանակ'],
            ['account.stats.lifetime', 'ru', 'За всё время'],

            ['account.stats.order_breakdown', 'en', 'Order breakdown'],
            ['account.stats.order_breakdown', 'hy', 'Պատվերների բաշխում'],
            ['account.stats.order_breakdown', 'ru', 'Разбивка заказов'],

            ['account.stats.by_currency', 'en', 'By currency'],
            ['account.stats.by_currency', 'hy', 'Ըստ արժույթի'],
            ['account.stats.by_currency', 'ru', 'По валюте'],

            ['account.stats.loyalty_tier', 'en', 'Loyalty tier'],
            ['account.stats.loyalty_tier', 'hy', 'Հավատարմության մակարդակ'],
            ['account.stats.loyalty_tier', 'ru', 'Уровень лояльности'],

            ['account.stats.points_balance', 'en', 'Points balance'],
            ['account.stats.points_balance', 'hy', 'Միավորների մնացորդ'],
            ['account.stats.points_balance', 'ru', 'Баланс баллов'],

            ['account.stats.vouchers', 'en', 'Vouchers'],
            ['account.stats.vouchers', 'hy', 'Վաուչերներ'],
            ['account.stats.vouchers', 'ru', 'Ваучеры'],

            // ── Admin empty-state labels (8 tables) ──────────────────────
            ['admin.approvals.empty', 'en', 'No approval requests yet.'],
            ['admin.approvals.empty', 'hy', 'Հաստատման հարցումներ դեռ չկան։'],
            ['admin.approvals.empty', 'ru', 'Заявок на утверждение пока нет.'],

            ['admin.banners.empty', 'en', 'No banners configured.'],
            ['admin.banners.empty', 'hy', 'Banner-ներ դեռ չկան։'],
            ['admin.banners.empty', 'ru', 'Баннеры не настроены.'],

            ['admin.locations.empty_countries', 'en', 'No countries match the filter.'],
            ['admin.locations.empty_countries', 'hy', 'Ֆիլտրին համապատասխան երկրներ չկան։'],
            ['admin.locations.empty_countries', 'ru', 'Стран, соответствующих фильтру, нет.'],

            ['admin.locations.empty_regions', 'en', 'No regions match the filter.'],
            ['admin.locations.empty_regions', 'hy', 'Ֆիլտրին համապատասխան մարզեր չկան։'],
            ['admin.locations.empty_regions', 'ru', 'Регионов, соответствующих фильтру, нет.'],

            ['admin.locations.empty_cities', 'en', 'No cities match the filter.'],
            ['admin.locations.empty_cities', 'hy', 'Ֆիլտրին համապատասխան քաղաքներ չկան։'],
            ['admin.locations.empty_cities', 'ru', 'Городов, соответствующих фильтру, нет.'],

            ['admin.package_orders.empty', 'en', 'No package orders yet.'],
            ['admin.package_orders.empty', 'hy', 'Փաթեթի պատվերներ դեռ չկան։'],
            ['admin.package_orders.empty', 'ru', 'Заказов пакетов пока нет.'],

            ['admin.payments.empty', 'en', 'No payments recorded.'],
            ['admin.payments.empty', 'hy', 'Վճարումներ դեռ չեն գրանցվել։'],
            ['admin.payments.empty', 'ru', 'Платежи не зарегистрированы.'],

            ['admin.reviews.empty', 'en', 'No reviews yet.'],
            ['admin.reviews.empty', 'hy', 'Կարծիքներ դեռ չկան։'],
            ['admin.reviews.empty', 'ru', 'Отзывов пока нет.'],

            ['admin.seller_applications.empty', 'en', 'No seller applications.'],
            ['admin.seller_applications.empty', 'hy', 'Վաճառողի դիմումներ չկան։'],
            ['admin.seller_applications.empty', 'ru', 'Заявок продавцов нет.'],

            ['admin.support.empty', 'en', 'No support tickets.'],
            ['admin.support.empty', 'hy', 'Աջակցության հարցումներ չկան։'],
            ['admin.support.empty', 'ru', 'Обращений в поддержку нет.'],

            // ── Admin → Users edit form (Bucket-3) ───────────────────────
            ['admin.users.err_update', 'en', 'Failed to update user.'],
            ['admin.users.err_update', 'hy', 'Չհաջողվեց թարմացնել օգտատիրոջը։'],
            ['admin.users.err_update', 'ru', 'Не удалось обновить пользователя.'],

            ['admin.users.save_success', 'en', 'User saved.'],
            ['admin.users.save_success', 'hy', 'Օգտատերը պահպանված է։'],
            ['admin.users.save_success', 'ru', 'Пользователь сохранён.'],

            ['admin.users.field.name', 'en', 'Full name'],
            ['admin.users.field.name', 'hy', 'Անուն ազգանուն'],
            ['admin.users.field.name', 'ru', 'Полное имя'],

            ['admin.users.field.phone', 'en', 'Phone number'],
            ['admin.users.field.phone', 'hy', 'Հեռախոսահամար'],
            ['admin.users.field.phone', 'ru', 'Номер телефона'],

            ['admin.users.field.language', 'en', 'Preferred language'],
            ['admin.users.field.language', 'hy', 'Նախընտրելի լեզու'],
            ['admin.users.field.language', 'ru', 'Предпочитаемый язык'],

            ['admin.users.field.nationality', 'en', 'Nationality'],
            ['admin.users.field.nationality', 'hy', 'Քաղաքացիություն'],
            ['admin.users.field.nationality', 'ru', 'Гражданство'],

            ['admin.users.field.birth_date', 'en', 'Date of birth'],
            ['admin.users.field.birth_date', 'hy', 'Ծննդյան ամսաթիվ'],
            ['admin.users.field.birth_date', 'ru', 'Дата рождения'],

            ['admin.users.field.status', 'en', 'Status'],
            ['admin.users.field.status', 'hy', 'Կարգավիճակ'],
            ['admin.users.field.status', 'ru', 'Статус'],

            ['admin.users.section.personal', 'en', 'Personal information'],
            ['admin.users.section.personal', 'hy', 'Անձնական տվյալներ'],
            ['admin.users.section.personal', 'ru', 'Личные данные'],

            ['admin.users.section.companies', 'en', 'Companies'],
            ['admin.users.section.companies', 'hy', 'Ընկերություններ'],
            ['admin.users.section.companies', 'ru', 'Компании'],

            // ── Document upload prompts (insurance, visa) ────────────────
            ['insurance.upload_passport_3x4', 'en', 'Upload a 3×4 passport photo'],
            ['insurance.upload_passport_3x4', 'hy', 'Վերբեռնեք 3×4 անձնագրային լուսանկար'],
            ['insurance.upload_passport_3x4', 'ru', 'Загрузите паспортную фотографию 3×4'],

            ['visa.upload_passport_3x4', 'en', 'Upload a 3×4 passport photo'],
            ['visa.upload_passport_3x4', 'hy', 'Վերբեռնեք 3×4 անձնագրային լուսանկար'],
            ['visa.upload_passport_3x4', 'ru', 'Загрузите паспортную фотографию 3×4'],
        ];

        $now = now();

        foreach ($rows as [$key, $lang, $value]) {
            DB::table('ui_translations')->updateOrInsert(
                ['key' => $key, 'language_code' => $lang],
                ['value' => $value, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->whereIn('key', [
                'account.notifications.mark_all_read',
                'account.notifications.mark_read',
                'account.notifications.marking',
                'account.stats.title',
                'account.stats.subtitle',
                'account.stats.activity',
                'account.stats.orders',
                'account.stats.paid_orders',
                'account.stats.total_spent',
                'account.stats.avg_order',
                'account.stats.first_order',
                'account.stats.last_order',
                'account.stats.last_30_days',
                'account.stats.lifetime',
                'account.stats.order_breakdown',
                'account.stats.by_currency',
                'account.stats.loyalty_tier',
                'account.stats.points_balance',
                'account.stats.vouchers',
                'admin.approvals.empty',
                'admin.banners.empty',
                'admin.locations.empty_countries',
                'admin.locations.empty_regions',
                'admin.locations.empty_cities',
                'admin.package_orders.empty',
                'admin.payments.empty',
                'admin.reviews.empty',
                'admin.seller_applications.empty',
                'admin.support.empty',
                'admin.users.err_update',
                'admin.users.save_success',
                'admin.users.field.name',
                'admin.users.field.phone',
                'admin.users.field.language',
                'admin.users.field.nationality',
                'admin.users.field.birth_date',
                'admin.users.field.status',
                'admin.users.section.personal',
                'admin.users.section.companies',
                'insurance.upload_passport_3x4',
                'visa.upload_passport_3x4',
            ])
            ->delete();
    }
};

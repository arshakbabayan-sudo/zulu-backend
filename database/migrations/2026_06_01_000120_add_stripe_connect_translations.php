<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * P0-1 step 1.1 — admin labels for the Stripe Connect card + sidebar tab.
 *
 * Seeds three groups:
 *   - admin.nav.tab.bucket3.payments   ("Payments" tab in My company group)
 *   - admin.stripe_connect.*           Card title/subtitle/pills/CTAs.
 *
 * Armenian first per the user-facing rule; English fallback lives in the
 * component but the DB row makes the localized version stick across
 * sidebar render + card render.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            // Sidebar tab label
            'admin.nav.tab.bucket3.payments' => [
                'en' => 'Payments',
                'hy' => 'Վճարումներ',
                'ru' => 'Платежи',
            ],

            // Card chrome
            'admin.stripe_connect.title' => [
                'en' => 'Stripe Connect',
                'hy' => 'Stripe Connect',
                'ru' => 'Stripe Connect',
            ],
            'admin.stripe_connect.subtitle' => [
                'en' => 'Connect your Stripe Express account so the platform can transfer your share of each booking.',
                'hy' => 'Միացրու քո Stripe Express հաշիվը, որպեսզի հարթակը կարողանա ամեն ամրագրման քո մասը փոխանցել քեզ։',
                'ru' => 'Подключите свой Stripe Express, чтобы платформа могла переводить вам вашу долю от каждой брони.',
            ],

            // Status pills
            'admin.stripe_connect.status.ready' => [
                'en' => 'Ready',
                'hy' => 'Պատրաստ է',
                'ru' => 'Готово',
            ],
            'admin.stripe_connect.status.pending' => [
                'en' => 'Setup in progress',
                'hy' => 'Կարգավորումը ընթացքում է',
                'ru' => 'Настройка в процессе',
            ],
            'admin.stripe_connect.status.not_connected' => [
                'en' => 'Not connected',
                'hy' => 'Միացված չէ',
                'ru' => 'Не подключено',
            ],
            'admin.stripe_connect.status.error' => [
                'en' => 'Sync error',
                'hy' => 'Համաժամացման սխալ',
                'ru' => 'Ошибка синхронизации',
            ],

            // Sub-status flags
            'admin.stripe_connect.flag.details' => [
                'en' => 'Details submitted',
                'hy' => 'Տվյալները լրացված են',
                'ru' => 'Данные заполнены',
            ],
            'admin.stripe_connect.flag.charges' => [
                'en' => 'Charges enabled',
                'hy' => 'Գանձումը միացված է',
                'ru' => 'Списания разрешены',
            ],
            'admin.stripe_connect.flag.payouts' => [
                'en' => 'Payouts enabled',
                'hy' => 'Փոխանցումները միացված են',
                'ru' => 'Выплаты разрешены',
            ],

            // CTA buttons
            'admin.stripe_connect.cta.connect' => [
                'en' => 'Connect Stripe',
                'hy' => 'Միացնել Stripe',
                'ru' => 'Подключить Stripe',
            ],
            'admin.stripe_connect.cta.continue' => [
                'en' => 'Continue setup',
                'hy' => 'Շարունակել կարգավորումը',
                'ru' => 'Продолжить настройку',
            ],
            'admin.stripe_connect.cta.update' => [
                'en' => 'Update Stripe info',
                'hy' => 'Թարմացնել տվյալները',
                'ru' => 'Обновить данные',
            ],
            'admin.stripe_connect.cta.busy' => [
                'en' => 'Opening Stripe…',
                'hy' => 'Stripe-ը բացվում է…',
                'ru' => 'Открываем Stripe…',
            ],

            // Errors + page header
            'admin.stripe_connect.error.load' => [
                'en' => "Couldn't load Stripe Connect status. Try refresh.",
                'hy' => 'Չհաջողվեց բեռնել Stripe Connect-ի կարգավիճակը։ Թարմացրու էջը։',
                'ru' => 'Не удалось загрузить статус Stripe Connect. Обновите страницу.',
            ],
            'admin.stripe_connect.error.link' => [
                'en' => "Couldn't start Stripe onboarding.",
                'hy' => 'Չհաջողվեց բացել Stripe-ի կարգավորման էջը։',
                'ru' => 'Не удалось открыть страницу настройки Stripe.',
            ],
            'admin.stripe_connect.error.no_url' => [
                'en' => "Stripe didn't return an onboarding URL.",
                'hy' => 'Stripe-ը հղում չվերադարձրեց։',
                'ru' => 'Stripe не вернул ссылку для настройки.',
            ],
            'admin.payments.page_title' => [
                'en' => 'Payments',
                'hy' => 'Վճարումներ',
                'ru' => 'Платежи',
            ],
            'admin.payments.page_subtitle' => [
                'en' => 'Connect Stripe to receive your share of bookings on your bank account.',
                'hy' => 'Միացրու Stripe-ը, որպեսզի ամրագրումներից քո մասը հասնի քո բանկային հաշվին։',
                'ru' => 'Подключите Stripe, чтобы получать свою долю от броней на банковский счёт.',
            ],
            'admin.payments.no_company' => [
                'en' => "You don't have a company assigned. Stripe Connect is managed per company.",
                'hy' => 'Քեզ կցված ընկերություն չկա։ Stripe Connect-ը կարգավորվում է ընկերության համար։',
                'ru' => 'К вам не привязана компания. Stripe Connect настраивается для компании.',
            ],
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
            'admin.nav.tab.bucket3.payments',
            'admin.stripe_connect.title',
            'admin.stripe_connect.subtitle',
            'admin.stripe_connect.status.ready',
            'admin.stripe_connect.status.pending',
            'admin.stripe_connect.status.not_connected',
            'admin.stripe_connect.status.error',
            'admin.stripe_connect.flag.details',
            'admin.stripe_connect.flag.charges',
            'admin.stripe_connect.flag.payouts',
            'admin.stripe_connect.cta.connect',
            'admin.stripe_connect.cta.continue',
            'admin.stripe_connect.cta.update',
            'admin.stripe_connect.cta.busy',
            'admin.stripe_connect.error.load',
            'admin.stripe_connect.error.link',
            'admin.stripe_connect.error.no_url',
            'admin.payments.page_title',
            'admin.payments.page_subtitle',
            'admin.payments.no_company',
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

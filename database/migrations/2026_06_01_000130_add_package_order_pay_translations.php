<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * P0-1 step 1.3 — i18n for the customer-side Stripe Elements pay page
 * + the "awaiting confirmation" banner on /package-orders/{id}.
 *
 * EN duplicates the defaults already in zulu-frontend-next/lib/lang.ts; we
 * still seed it so a future cache eviction doesn't blank the UI. HY + RU
 * are real translations (no Cyrillic in the Armenian column — checked).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            'package.order.payment_awaiting_confirmation' => [
                'en' => 'Payment received — waiting for bank confirmation.',
                'hy' => 'Վճարումը ստացված է — սպասում ենք բանկի հաստատմանը։',
                'ru' => 'Оплата получена — ожидаем подтверждения банка.',
            ],
            'package.order.pay.title' => [
                'en' => 'Complete your payment',
                'hy' => 'Ավարտի՛ր վճարումը',
                'ru' => 'Завершите оплату',
            ],
            'package.order.pay.subtitle' => [
                'en' => 'Enter your card details below. Your payment is processed securely by Stripe.',
                'hy' => 'Ներքևում մուտքագրի՛ր քո քարտի տվյալները։ Վճարումը մշակվում է Stripe-ի կողմից՝ ապահով։',
                'ru' => 'Введите данные карты ниже. Платёж обрабатывается Stripe в защищённом виде.',
            ],
            'package.order.pay.back_to_order' => [
                'en' => 'Back to order',
                'hy' => 'Վերադառնալ պատվերին',
                'ru' => 'Назад к заказу',
            ],
            'package.order.pay.amount_due' => [
                'en' => 'Amount due',
                'hy' => 'Վճարման ենթակա գումար',
                'ru' => 'Сумма к оплате',
            ],
            'package.order.pay.pay_button' => [
                'en' => 'Pay now',
                'hy' => 'Վճարել',
                'ru' => 'Оплатить',
            ],
            'package.order.pay.login_required' => [
                'en' => 'Please sign in to pay for this order.',
                'hy' => 'Մուտք գործիր, որպեսզի վճարես այս պատվերի համար։',
                'ru' => 'Войдите, чтобы оплатить этот заказ.',
            ],
            'package.order.pay.intent_failed' => [
                'en' => "Couldn't start the payment. Please go back and try again.",
                'hy' => 'Չհաջողվեց սկսել վճարումը։ Վերադարձիր և կրկին փորձիր։',
                'ru' => 'Не удалось начать оплату. Вернитесь назад и попробуйте снова.',
            ],
            'package.order.pay.confirm_failed' => [
                'en' => "We couldn't confirm the payment. Please try again or use a different card.",
                'hy' => 'Չհաջողվեց հաստատել վճարումը։ Կրկին փորձիր կամ օգտագործիր այլ քարտ։',
                'ru' => 'Не удалось подтвердить оплату. Попробуйте снова или используйте другую карту.',
            ],
            'package.order.pay.unexpected_status' => [
                'en' => 'Stripe returned an unexpected status:',
                'hy' => 'Stripe-ը անսպասելի կարգավիճակ վերադարձրեց՝',
                'ru' => 'Stripe вернул неожиданный статус:',
            ],
            'package.order.pay.stripe_notice' => [
                'en' => 'Secured by Stripe. Your card details never touch our servers.',
                'hy' => 'Ապահովում է Stripe-ը։ Քո քարտի տվյալները երբեք չեն հասնում մեր սերվերին։',
                'ru' => 'Защищено Stripe. Данные вашей карты не попадают на наши серверы.',
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
            'package.order.payment_awaiting_confirmation',
            'package.order.pay.title',
            'package.order.pay.subtitle',
            'package.order.pay.back_to_order',
            'package.order.pay.amount_due',
            'package.order.pay.pay_button',
            'package.order.pay.login_required',
            'package.order.pay.intent_failed',
            'package.order.pay.confirm_failed',
            'package.order.pay.unexpected_status',
            'package.order.pay.stripe_notice',
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

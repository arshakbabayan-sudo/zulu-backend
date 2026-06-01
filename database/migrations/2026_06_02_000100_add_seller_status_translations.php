<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * P0-3 — i18n for the operator-facing "Seller status" page (My company group):
 * nav tab, page/card titles, status labels, service-type labels, actions,
 * errors. HY + RU are real translations (no Cyrillic in the Armenian column —
 * checked). EN mirrors the in-component fallbacks.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            'admin.nav.tab.bucket3.seller_status' => [
                'en' => 'Seller status',
                'hy' => 'Վաճառողի կարգավիճակ',
                'ru' => 'Статус продавца',
            ],
            'admin.seller_status.page_title' => [
                'en' => 'Seller status',
                'hy' => 'Վաճառողի կարգավիճակ',
                'ru' => 'Статус продавца',
            ],
            'admin.seller_status.page_subtitle' => [
                'en' => "Track which services you're approved to sell and apply for new ones.",
                'hy' => 'Հետևիր՝ ո՛ր ծառայությունները կարող ես վաճառել, ու դիմիր նորերի համար։',
                'ru' => 'Отслеживайте, какие услуги вы можете продавать, и подавайте заявки на новые.',
            ],
            'admin.seller_status.no_company' => [
                'en' => "You don't have a company assigned. Seller status is managed per company.",
                'hy' => 'Քեզ ընկերություն չի կցված։ Վաճառողի կարգավիճակը կառավարվում է ըստ ընկերության։',
                'ru' => 'К вам не привязана компания. Статус продавца управляется по компании.',
            ],
            'admin.seller_status.title' => [
                'en' => 'Seller status',
                'hy' => 'Վաճառողի կարգավիճակ',
                'ru' => 'Статус продавца',
            ],
            'admin.seller_status.subtitle' => [
                'en' => 'See which service types are approved, in process, or rejected — and apply to sell new ones.',
                'hy' => 'Տես՝ ո՛ր ծառայություններն են հաստատված, ընթացքում, կամ մերժված — ու դիմիր նոր ծառայություն վաճառելու համար։',
                'ru' => 'Смотрите, какие услуги одобрены, на рассмотрении или отклонены — и подайте заявку на продажу новой услуги.',
            ],
            'admin.seller_status.status.pending' => [
                'en' => 'In process',
                'hy' => 'Ընթացքում',
                'ru' => 'В процессе',
            ],
            'admin.seller_status.status.under_review' => [
                'en' => 'Under review',
                'hy' => 'Քննարկման փուլում',
                'ru' => 'На рассмотрении',
            ],
            'admin.seller_status.status.approved' => [
                'en' => 'Approved',
                'hy' => 'Հաստատված',
                'ru' => 'Одобрено',
            ],
            'admin.seller_status.status.rejected' => [
                'en' => 'Rejected',
                'hy' => 'Մերժված',
                'ru' => 'Отклонено',
            ],
            'admin.seller_status.service.hotel' => [
                'en' => 'Hotels',
                'hy' => 'Հյուրանոցներ',
                'ru' => 'Отели',
            ],
            'admin.seller_status.service.flight' => [
                'en' => 'Flights',
                'hy' => 'Թռիչքներ',
                'ru' => 'Авиабилеты',
            ],
            'admin.seller_status.service.car' => [
                'en' => 'Cars',
                'hy' => 'Մեքենաներ',
                'ru' => 'Автомобили',
            ],
            'admin.seller_status.service.transfer' => [
                'en' => 'Transfers',
                'hy' => 'Փոխադրումներ',
                'ru' => 'Трансферы',
            ],
            'admin.seller_status.service.excursion' => [
                'en' => 'Excursions',
                'hy' => 'Էքսկուրսիաներ',
                'ru' => 'Экскурсии',
            ],
            'admin.seller_status.service.package' => [
                'en' => 'Packages',
                'hy' => 'Փաթեթներ',
                'ru' => 'Пакеты',
            ],
            'admin.seller_status.service.visa' => [
                'en' => 'Visa',
                'hy' => 'Վիզա',
                'ru' => 'Виза',
            ],
            'admin.seller_status.apply' => [
                'en' => 'Apply',
                'hy' => 'Դիմել',
                'ru' => 'Подать заявку',
            ],
            'admin.seller_status.apply_again' => [
                'en' => 'Apply again',
                'hy' => 'Կրկին դիմել',
                'ru' => 'Подать снова',
            ],
            'admin.seller_status.applying' => [
                'en' => 'Submitting…',
                'hy' => 'Ուղարկվում է…',
                'ru' => 'Отправка…',
            ],
            'admin.seller_status.error_load' => [
                'en' => "Couldn't load your seller status.",
                'hy' => 'Չհաջողվեց բերել կարգավիճակը։',
                'ru' => 'Не удалось загрузить статус.',
            ],
            'admin.seller_status.error_apply' => [
                'en' => "Couldn't submit your application.",
                'hy' => 'Չհաջողվեց ուղարկել դիմումը։',
                'ru' => 'Не удалось отправить заявку.',
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
            'admin.nav.tab.bucket3.seller_status',
            'admin.seller_status.page_title',
            'admin.seller_status.page_subtitle',
            'admin.seller_status.no_company',
            'admin.seller_status.title',
            'admin.seller_status.subtitle',
            'admin.seller_status.status.pending',
            'admin.seller_status.status.under_review',
            'admin.seller_status.status.approved',
            'admin.seller_status.status.rejected',
            'admin.seller_status.service.hotel',
            'admin.seller_status.service.flight',
            'admin.seller_status.service.car',
            'admin.seller_status.service.transfer',
            'admin.seller_status.service.excursion',
            'admin.seller_status.service.package',
            'admin.seller_status.service.visa',
            'admin.seller_status.apply',
            'admin.seller_status.apply_again',
            'admin.seller_status.applying',
            'admin.seller_status.error_load',
            'admin.seller_status.error_apply',
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

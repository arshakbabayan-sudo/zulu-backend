<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Footer headings and link labels were missing from ui_translations, so
     * t() fell through to the English-only defaultUiTranslations map in the
     * frontend's lib/lang.ts. That is why the live site rendered "Pages",
     * "Help", "Contact us", etc. even when the user had picked Russian or
     * Armenian.
     */
    public function up(): void
    {
        $now = now();

        // [key, en, ru, hy]
        $rows = [
            // Column titles
            ['footer.col_travel_title', 'Travel with ZULU', 'Путешествуйте с ZULU', 'Ճանապարհորդիր ZULU-ի հետ'],
            ['footer.col_pages_title', 'Pages', 'Страницы', 'Էջեր'],
            ['footer.col_help_title', 'Help', 'Помощь', 'Օգնություն'],
            ['footer.col_contact_title', 'Contact us', 'Свяжитесь с нами', 'Կապ մեզ հետ'],

            // Service links
            ['footer.link.flights', 'Flights', 'Авиабилеты', 'Թռիչքներ'],
            ['footer.link.stays', 'Stays', 'Проживание', 'Հյուրանոցներ'],
            ['footer.link.packages', 'Packages', 'Туры', 'Փաթեթներ'],
            ['footer.link.transfer', 'Transfer', 'Трансфер', 'Փոխադրում'],
            ['footer.link.car_rental', 'Car rental', 'Аренда авто', 'Մեքենայի վարձույթ'],
            ['footer.link.excursions', 'Excursions', 'Экскурсии', 'Էքսկուրսիաներ'],

            // Pages column
            ['footer.link.about_us', 'About us', 'О нас', 'Մեր մասին'],
            ['footer.link.careers', 'Careers', 'Карьера', 'Կարիերա'],
            ['footer.link.booking', 'Booking', 'Бронирование', 'Ամրագրում'],
            ['footer.link.payment_method', 'Payment method', 'Способ оплаты', 'Վճարման եղանակ'],
            ['footer.link.group_bookings', 'Group bookings', 'Групповые бронирования', 'Խմբային ամրագրումներ'],
            ['footer.link.reviews', 'Reviews', 'Отзывы', 'Կարծիքներ'],

            // Help column
            ['footer.link.how_to_buy', 'How to buy?', 'Как купить?', 'Ինչպես գնել։'],
            ['footer.link.how_to_pay', 'How to pay?', 'Как оплатить?', 'Ինչպես վճարել։'],
            ['footer.link.support', 'Support', 'Поддержка', 'Աջակցություն'],
            ['footer.link.faq', 'FAQ', 'Часто задаваемые вопросы', 'Հաճախ տրվող հարցեր'],

            // Contact column + misc
            ['footer.contact_phone', '+374(60) 400777', '+374(60) 400777', '+374(60) 400777'],
            ['footer.contact_email', 'info@zulu.am', 'info@zulu.am', 'info@zulu.am'],
            ['footer.terms_of_use', 'Terms of use', 'Условия использования', 'Օգտագործման պայմաններ'],
            ['footer.privacy_policy', 'Privacy Policy', 'Политика конфиденциальности', 'Գաղտնիության քաղաքականություն'],
            ['footer.copyright_brand', 'ZULU', 'ZULU', 'ZULU'],
            ['footer.find_us', 'Find us', 'Найдите нас', 'Գտեք մեզ'],
            ['footer.instagram', 'Instagram', 'Instagram', 'Instagram'],
            ['footer.youtube', 'YouTube', 'YouTube', 'YouTube'],
            ['footer.download_app_store', 'Download on the App Store', 'Скачать в App Store', 'Ներբեռնել App Store-ից'],
            ['footer.download_google_play', 'Get it on Google Play', 'Доступно в Google Play', 'Հասանելի է Google Play-ում'],
            ['aria.zulu_home', 'ZULU home', 'Главная ZULU', 'ZULU-ի գլխավոր էջ'],
        ];

        foreach ($rows as $r) {
            [$key, $en, $ru, $hy] = $r;
            foreach (['en' => $en, 'ru' => $ru, 'hy' => $hy] as $lang => $value) {
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
        // No-op — removing footer translation keys would re-introduce the bug.
    }
};

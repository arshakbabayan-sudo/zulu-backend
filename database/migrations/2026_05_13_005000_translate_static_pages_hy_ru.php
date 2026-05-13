<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Populate body_html_hy + body_html_ru for the 5 static pages with
     * proper Armenian / Russian translations of the initial seed content
     * (commit fb674dd).
     *
     * Admins can still overwrite via /platform/settings/pages/{slug} —
     * this migration only fills the *_hy / *_ru columns that previously
     * held an English-language copy.
     */
    public function up(): void
    {
        $now = now();

        $pages = [
            // ─────── About ───────
            'about' => [
                'hy' => '<h1>ZULU-ի մասին</h1><p>ZULU-ն Հայաստանի և աշխարհի լավագույն օպերատորների հետ ճանապարհորդներին կապող հարթակ է: Հյուրանոցներ, թռիչքներ, փոխադրումներ, փաթեթներ` մեկ հարթակում, մեկ հաշվով:</p>',
                'ru' => '<h1>О ZULU</h1><p>ZULU — это платформа, объединяющая путешественников с лучшими операторами Армении и мира. Отели, авиабилеты, трансферы, туры — всё на одной платформе, в одном аккаунте.</p>',
            ],

            // ─────── Contact ───────
            'contact' => [
                'hy' => '<h1>Կապ մեզ հետ</h1><p>Հեռախոսը, էլ. փոստը և հասցեն ցույց են տրված footer-ում (էջի ստորին հատվածում): Մենք սովորաբար պատասխանում ենք մեկ աշխատանքային օրվա ընթացքում:</p>',
                'ru' => '<h1>Свяжитесь с нами</h1><p>Телефон, электронная почта и адрес указаны в подвале сайта. Мы обычно отвечаем в течение одного рабочего дня.</p>',
            ],

            // ─────── Terms ───────
            'terms' => [
                'hy' => '<h1>Տեղակալ տեքստ — փոխարինիր իրական պայմաններով</h1><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Այս բաժնում շուտով կհրապարակվեն ZULU հարթակից օգտվելու վերաբերյալ ամբողջական պայմանները:</p><p>Մինչ իրավաբանական տեքստի հրապարակումը, օգտատերերը կարող են կապ հաստատել մեզ հետ ցանկացած հարցի վերաբերյալ /contact էջից:</p>',
                'ru' => '<h1>Заполнитель — замените реальными условиями</h1><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. В этом разделе скоро будут опубликованы полные условия использования платформы ZULU.</p><p>До публикации юридического текста пользователи могут связаться с нами по любому вопросу через страницу /contact.</p>',
            ],

            // ─────── Privacy ───────
            'privacy' => [
                'hy' => '<h1>Տեղակալ տեքստ — փոխարինիր իրական գաղտնիության քաղաքականությամբ</h1><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Այս բաժնում շուտով կհրապարակվի ZULU հարթակի կողմից անձնական տվյալների մշակման ամբողջական քաղաքականությունը:</p>',
                'ru' => '<h1>Заполнитель — замените реальной политикой конфиденциальности</h1><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. В этом разделе скоро будет опубликована полная политика обработки персональных данных платформы ZULU.</p>',
            ],

            // ─────── Cookies ───────
            'cookies' => [
                'hy' => '<h1>Տեղակալ տեքստ — փոխարինիր իրական cookies-ի քաղաքականությամբ</h1><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Այս էջում շուտով կհրապարակվի կայքի կողմից cookies-ի (փոքր տվյալների, որոնք պահվում են ձեր զննարկիչում) օգտագործման ամբողջական քաղաքականությունը:</p>',
                'ru' => '<h1>Заполнитель — замените реальной политикой cookies</h1><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. На этой странице скоро будет опубликована полная политика использования cookies (небольших файлов данных, сохраняемых в вашем браузере) на сайте.</p>',
            ],
        ];

        foreach ($pages as $slug => $bodies) {
            DB::table('pages')
                ->where('page_slug', $slug)
                ->update([
                    'body_html_hy' => $bodies['hy'],
                    'body_html_ru' => $bodies['ru'],
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Revert HY/RU back to the English body so /about etc still render.
        $slugs = ['about', 'contact', 'terms', 'privacy', 'cookies'];
        foreach ($slugs as $slug) {
            $english = DB::table('pages')->where('page_slug', $slug)->value('body_html_en');
            DB::table('pages')
                ->where('page_slug', $slug)
                ->update([
                    'body_html_hy' => $english,
                    'body_html_ru' => $english,
                    'updated_at' => now(),
                ]);
        }
    }
};

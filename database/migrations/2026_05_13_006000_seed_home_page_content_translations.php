<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed HY + RU content translations for:
     *   1. Page-level meta (page_translations table)
     *   2. Widget content on the home page (widget_content_translations)
     *
     * Trigger: user feedback (Telegram msg 19 + screenshots) — when
     * switching the public site to HY or RU, the hero / section titles
     * etc. were still rendering English text because no rows existed
     * in widget_content_translations for those languages.
     *
     * Admins can overwrite any of these via /pages/{id}/edit ("HY"/"RU"
     * tab) — this migration just provides a sane default.
     */
    public function up(): void
    {
        $now = now();

        // ─────────── page_translations ───────────
        $pageRows = [
            // Home page (id=1)
            ['slug' => 'home-page', 'lang' => 'hy', 'name' => 'Գլխավոր էջ', 'meta_title' => null, 'meta_description' => null],
            ['slug' => 'home-page', 'lang' => 'ru', 'name' => 'Главная страница', 'meta_title' => null, 'meta_description' => null],

            // About
            ['slug' => 'about', 'lang' => 'hy', 'name' => 'Մեր մասին', 'meta_title' => 'ZULU-ի մասին', 'meta_description' => 'ZULU հարթակի մասին'],
            ['slug' => 'about', 'lang' => 'ru', 'name' => 'О нас', 'meta_title' => 'О ZULU', 'meta_description' => 'О платформе ZULU'],

            // Contact
            ['slug' => 'contact', 'lang' => 'hy', 'name' => 'Կապ', 'meta_title' => 'Կապ մեզ հետ', 'meta_description' => 'Կապի տվյալներ`  հեռախոս, էլ. փոստ, հասցե'],
            ['slug' => 'contact', 'lang' => 'ru', 'name' => 'Контакты', 'meta_title' => 'Свяжитесь с нами', 'meta_description' => 'Контактная информация` телефон, электронная почта, адрес'],

            // Terms
            ['slug' => 'terms', 'lang' => 'hy', 'name' => 'Պայմաններ', 'meta_title' => 'Օգտվելու պայմաններ', 'meta_description' => 'ZULU հարթակից օգտվելու պայմաններ'],
            ['slug' => 'terms', 'lang' => 'ru', 'name' => 'Условия', 'meta_title' => 'Условия использования', 'meta_description' => 'Условия использования платформы ZULU'],

            // Privacy
            ['slug' => 'privacy', 'lang' => 'hy', 'name' => 'Գաղտնիություն', 'meta_title' => 'Գաղտնիության քաղաքականություն', 'meta_description' => 'Անձնական տվյալների մշակման քաղաքականություն'],
            ['slug' => 'privacy', 'lang' => 'ru', 'name' => 'Конфиденциальность', 'meta_title' => 'Политика конфиденциальности', 'meta_description' => 'Политика обработки персональных данных'],

            // Cookies
            ['slug' => 'cookies', 'lang' => 'hy', 'name' => 'Cookies', 'meta_title' => 'Cookies-ի քաղաքականություն', 'meta_description' => 'Cookies-ի օգտագործման քաղաքականություն'],
            ['slug' => 'cookies', 'lang' => 'ru', 'name' => 'Cookies', 'meta_title' => 'Политика cookies', 'meta_description' => 'Политика использования cookies'],
        ];

        foreach ($pageRows as $row) {
            $page = DB::table('pages')->where('page_slug', $row['slug'])->first(['id', 'page_slug']);
            if (! $page) {
                continue;
            }
            DB::table('page_translations')->updateOrInsert(
                ['page_id' => $page->id, 'lang' => $row['lang']],
                [
                    'page_name' => $row['name'],
                    'page_slug' => $page->page_slug, // URL slug stays the same across languages
                    'meta_title' => $row['meta_title'],
                    'meta_keywords' => null,
                    'meta_description' => $row['meta_description'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        // ─────────── widget_content_translations ───────────
        // Home-page widgets seeded in commit e9be470.
        $homePage = DB::table('pages')->where('page_slug', 'home-page')->first(['id']);
        if (! $homePage) {
            return;
        }

        $widgetTranslations = [
            'home-page-hero' => [
                'hy' => [
                    'headline' => 'Գտիր քո հաջորդ ճանապարհորդությունը',
                    'subheadline' => 'Թռիչքներ, հյուրանոցներ, փոխադրումներ և փաթեթներ` մեկ տեղում:',
                    'background_image' => '',
                    'button_text' => 'Սկսել որոնումը',
                    'button_url' => '/flights',
                ],
                'ru' => [
                    'headline' => 'Найдите своё следующее приключение',
                    'subheadline' => 'Авиабилеты, отели, трансферы и туры — всё на одной платформе.',
                    'background_image' => '',
                    'button_text' => 'Начать поиск',
                    'button_url' => '/flights',
                ],
            ],
            'home-page-special-offers' => [
                'hy' => [
                    'section_title' => 'Հատուկ առաջարկներ',
                    'section_subtitle' => 'Մեր օպերատորների ձեռքով ընտրված գործարքներ',
                ],
                'ru' => [
                    'section_title' => 'Специальные предложения',
                    'section_subtitle' => 'Отобранные нашими операторами выгодные предложения',
                ],
            ],
            'home-page-popular-destinations' => [
                'hy' => ['section_title' => 'Հանրահայտ ուղղություններ'],
                'ru' => ['section_title' => 'Популярные направления'],
            ],
            'home-page-newsletter' => [
                'hy' => [
                    'title' => 'Բաժանորդագրվիր մեր տեղեկագրին',
                    'subtitle' => 'Առաջինը ստացիր բացառիկ առաջարկներ և մեր ծառայությունների մասին վերջին նորությունները ուղիղ քո էլ. փոստի արկղում:',
                    'bg_image' => '',
                    'button_text' => 'Միանալ',
                ],
                'ru' => [
                    'title' => 'Подпишитесь на нашу рассылку',
                    'subtitle' => 'Первыми получайте эксклюзивные предложения и новости о наших услугах прямо на вашу электронную почту.',
                    'bg_image' => '',
                    'button_text' => 'Присоединиться',
                ],
            ],
            'home-page-partners' => [
                'hy' => ['section_title' => 'Մեր գործընկերները'],
                'ru' => ['section_title' => 'Наши партнёры'],
            ],
            'home-page-bottom-newsletter' => [
                'hy' => [
                    'title' => 'Բաժանորդագրվիր մեր տեղեկագրին',
                    'subtitle' => 'Առաջինը ստացիր բացառիկ առաջարկներ և մեր ծառայությունների մասին վերջին նորությունները ուղիղ քո էլ. փոստի արկղում:',
                    'bg_image' => '',
                    'button_text' => 'Բաժանորդագրվել',
                ],
                'ru' => [
                    'title' => 'Подпишитесь на нашу рассылку',
                    'subtitle' => 'Первыми получайте эксклюзивные предложения и новости о наших услугах прямо на вашу электронную почту.',
                    'bg_image' => '',
                    'button_text' => 'Подписаться',
                ],
            ],
            'home-page-hero-settings' => [
                'hy' => [],
                'ru' => [],
            ],
        ];

        foreach ($widgetTranslations as $uiCardNumber => $byLang) {
            $widget = DB::table('widget_contents')
                ->where('page_id', $homePage->id)
                ->where('ui_card_number', $uiCardNumber)
                ->first(['id']);
            if (! $widget) {
                continue;
            }
            foreach ($byLang as $lang => $payload) {
                DB::table('widget_content_translations')->updateOrInsert(
                    ['widget_content_id' => $widget->id, 'lang' => $lang],
                    [
                        'page_id' => $homePage->id,
                        'widget_content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        // Remove only the rows we seeded (by page_id + lang), not all translations.
        $slugs = ['home-page', 'about', 'contact', 'terms', 'privacy', 'cookies'];
        $pageIds = DB::table('pages')->whereIn('page_slug', $slugs)->pluck('id');
        DB::table('page_translations')
            ->whereIn('page_id', $pageIds)
            ->whereIn('lang', ['hy', 'ru'])
            ->delete();

        $homePage = DB::table('pages')->where('page_slug', 'home-page')->first(['id']);
        if ($homePage) {
            DB::table('widget_content_translations')
                ->where('page_id', $homePage->id)
                ->whereIn('lang', ['hy', 'ru'])
                ->delete();
        }
    }
};

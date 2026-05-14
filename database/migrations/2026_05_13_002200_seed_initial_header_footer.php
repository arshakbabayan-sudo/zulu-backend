<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed the current static header menu + footer structure into the
     * new DB tables so that the live site keeps its layout when the
     * frontend switches to DB-driven reads (Sprint 2 Step 2.5).
     *
     * Matches Figma 1:4435 (header desktop) + 1:4563 (footer desktop).
     */
    public function up(): void
    {
        $now = now();

        // Header menu — About / Booking / Destinations (with children) /
        // Code check / Help. Earlier revisions seeded Booking → /bookings
        // and Code check → /code-check; neither route exists, so the URLs
        // are repointed at /account/trips and /account/vouchers respectively.
        $headerItems = [
            ['label_en' => 'About', 'label_ru' => 'О нас', 'label_hy' => 'Մեր մասին', 'url' => '/about', 'position' => 1],
            ['label_en' => 'Booking', 'label_ru' => 'Бронирование', 'label_hy' => 'Ամրագրում', 'url' => '/account/trips', 'position' => 2],
            ['label_en' => 'Destinations', 'label_ru' => 'Направления', 'label_hy' => 'Ուղղություններ', 'url' => '#', 'position' => 3],
            ['label_en' => 'Code check', 'label_ru' => 'Проверка кода', 'label_hy' => 'Կոդի ստուգում', 'url' => '/account/vouchers', 'position' => 4],
            ['label_en' => 'Help', 'label_ru' => 'Помощь', 'label_hy' => 'Օգնություն', 'url' => '/contact', 'position' => 5],
        ];

        foreach ($headerItems as $item) {
            DB::table('header_menu_items')->updateOrInsert(
                ['url' => $item['url'], 'parent_id' => null],
                array_merge($item, [
                    'parent_id' => null,
                    'is_visible' => true,
                    'open_in_new_tab' => false,
                    'updated_at' => $now,
                    'created_at' => $now,
                ])
            );
        }

        // Sub-items under Destinations
        $destinationsId = DB::table('header_menu_items')
            ->where('label_en', 'Destinations')->whereNull('parent_id')
            ->value('id');

        if ($destinationsId) {
            $children = [
                ['label_en' => 'Flights', 'label_ru' => 'Авиабилеты', 'label_hy' => 'Թռիչքներ', 'url' => '/flights', 'position' => 1],
                ['label_en' => 'Hotels', 'label_ru' => 'Отели', 'label_hy' => 'Հյուրանոցներ', 'url' => '/hotels', 'position' => 2],
                ['label_en' => 'Packages', 'label_ru' => 'Туры', 'label_hy' => 'Փաթեթներ', 'url' => '/packages', 'position' => 3],
                ['label_en' => 'Transfers', 'label_ru' => 'Трансферы', 'label_hy' => 'Փոխադրումներ', 'url' => '/transfers', 'position' => 4],
                ['label_en' => 'Cars', 'label_ru' => 'Авто', 'label_hy' => 'Մեքենաներ', 'url' => '/cars', 'position' => 5],
                ['label_en' => 'Excursions', 'label_ru' => 'Экскурсии', 'label_hy' => 'Էքսկուրսիաներ', 'url' => '/excursions', 'position' => 6],
            ];
            foreach ($children as $c) {
                DB::table('header_menu_items')->updateOrInsert(
                    ['url' => $c['url'], 'parent_id' => $destinationsId],
                    array_merge($c, [
                        'parent_id' => $destinationsId,
                        'is_visible' => true,
                        'open_in_new_tab' => false,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ])
                );
            }
        }

        // Footer columns — matches the existing Footer.tsx columns
        $columns = [
            ['slug' => 'travel', 'title_en' => 'Travel with ZULU', 'title_ru' => 'Путешествуйте с ZULU', 'title_hy' => 'Ճանապարհորդիր ZULU-ի հետ', 'position' => 1],
            ['slug' => 'company', 'title_en' => 'Company', 'title_ru' => 'Компания', 'title_hy' => 'Ընկերություն', 'position' => 2],
            ['slug' => 'help', 'title_en' => 'Help', 'title_ru' => 'Помощь', 'title_hy' => 'Օգնություն', 'position' => 3],
            ['slug' => 'contact', 'title_en' => 'Contact us', 'title_ru' => 'Свяжитесь с нами', 'title_hy' => 'Կապ մեզ հետ', 'position' => 4],
        ];

        foreach ($columns as $col) {
            DB::table('footer_columns')->updateOrInsert(
                ['slug' => $col['slug']],
                array_merge($col, [
                    'is_visible' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ])
            );
        }

        $travelId = DB::table('footer_columns')->where('slug', 'travel')->value('id');
        $companyId = DB::table('footer_columns')->where('slug', 'company')->value('id');
        $helpId = DB::table('footer_columns')->where('slug', 'help')->value('id');

        $linkSets = [
            $travelId => [
                ['label_en' => 'Flights', 'label_ru' => 'Авиабилеты', 'label_hy' => 'Թռիչքներ', 'url' => '/flights'],
                ['label_en' => 'Hotels', 'label_ru' => 'Отели', 'label_hy' => 'Հյուրանոցներ', 'url' => '/hotels'],
                ['label_en' => 'Packages', 'label_ru' => 'Туры', 'label_hy' => 'Փաթեթներ', 'url' => '/packages'],
                ['label_en' => 'Transfers', 'label_ru' => 'Трансферы', 'label_hy' => 'Փոխադրումներ', 'url' => '/transfers'],
                ['label_en' => 'Cars', 'label_ru' => 'Авто', 'label_hy' => 'Մեքենաներ', 'url' => '/cars'],
                ['label_en' => 'Excursions', 'label_ru' => 'Экскурсии', 'label_hy' => 'Էքսկուրսիաներ', 'url' => '/excursions'],
            ],
            $companyId => [
                ['label_en' => 'About us', 'label_ru' => 'О нас', 'label_hy' => 'Մեր մասին', 'url' => '/about'],
                ['label_en' => 'Careers', 'label_ru' => 'Карьера', 'label_hy' => 'Կարիերա', 'url' => '/careers'],
                ['label_en' => 'Blog', 'label_ru' => 'Блог', 'label_hy' => 'Բլոգ', 'url' => '/blog'],
                ['label_en' => 'Terms', 'label_ru' => 'Условия', 'label_hy' => 'Պայմաններ', 'url' => '/terms'],
                ['label_en' => 'Privacy', 'label_ru' => 'Конфиденциальность', 'label_hy' => 'Գաղտնիություն', 'url' => '/privacy'],
                ['label_en' => 'Cookies', 'label_ru' => 'Cookies', 'label_hy' => 'Cookies', 'url' => '/cookies'],
            ],
            $helpId => [
                ['label_en' => 'How to buy', 'label_ru' => 'Как купить', 'label_hy' => 'Ինչպես գնել', 'url' => '/contact'],
                ['label_en' => 'How to pay', 'label_ru' => 'Как оплатить', 'label_hy' => 'Ինչպես վճարել', 'url' => '/contact'],
                ['label_en' => 'Support', 'label_ru' => 'Поддержка', 'label_hy' => 'Աջակցություն', 'url' => '/contact'],
                ['label_en' => 'FAQ', 'label_ru' => 'Часто задаваемые вопросы', 'label_hy' => 'Հաճախ տրվող հարցեր', 'url' => '/contact'],
            ],
        ];

        foreach ($linkSets as $columnId => $links) {
            if (! $columnId) {
                continue;
            }
            $pos = 1;
            foreach ($links as $link) {
                DB::table('footer_links')->updateOrInsert(
                    ['column_id' => $columnId, 'url' => $link['url'], 'label_en' => $link['label_en']],
                    array_merge($link, [
                        'column_id' => $columnId,
                        'position' => $pos++,
                        'is_visible' => true,
                        'open_in_new_tab' => false,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ])
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('footer_links')->delete();
        DB::table('footer_columns')->delete();
        DB::table('header_menu_items')->delete();
    }
};

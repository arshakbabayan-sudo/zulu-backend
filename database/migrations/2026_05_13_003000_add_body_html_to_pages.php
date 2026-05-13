<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add rich-text body columns to pages so static pages (about,
     * terms, privacy, contact, cookies) can be edited from admin
     * with a TipTap WYSIWYG editor (Sprint 3 of ZULU CMS roadmap).
     *
     * Stores sanitized HTML strings. NULL means "no override — frontend
     * will render the static fallback baked into the React component".
     */
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->longText('body_html_en')->nullable()->after('is_bread_crumb');
            $table->longText('body_html_ru')->nullable()->after('body_html_en');
            $table->longText('body_html_hy')->nullable()->after('body_html_ru');
        });

        // Seed page rows for the 5 static pages if not already present
        $now = now();
        $staticPages = [
            ['page_slug' => 'about', 'page_name' => 'About us'],
            ['page_slug' => 'contact', 'page_name' => 'Contact'],
            ['page_slug' => 'terms', 'page_name' => 'Terms & Conditions'],
            ['page_slug' => 'privacy', 'page_name' => 'Privacy Policy'],
            ['page_slug' => 'cookies', 'page_name' => 'Cookies Policy'],
        ];

        foreach ($staticPages as $p) {
            DB::table('pages')->updateOrInsert(
                ['page_slug' => $p['page_slug']],
                [
                    'page_name' => $p['page_name'],
                    'status' => 1,
                    'enable_seo' => false,
                    'is_bread_crumb' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        // Lorem-ipsum placeholder bodies for Terms / Privacy / Cookies
        // so the public route has something to render before the user
        // provides the real legal text. Admins will overwrite these via
        // the new TipTap editor in /pages/{id}/edit.
        $lorem = '<h1>Placeholder — please replace with the real text</h1><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p><p>Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>';
        $aboutBody = '<h1>About ZULU</h1><p>We connect travelers with the best operators in Armenia and beyond. Hotels, flights, transfers, packages — one platform, one account.</p>';
        $contactBody = '<h1>Get in touch</h1><p>Phone, email, and address shown in the footer. We typically reply within one business day.</p>';

        $seeds = [
            ['slug' => 'about', 'en' => $aboutBody, 'ru' => $aboutBody, 'hy' => $aboutBody],
            ['slug' => 'contact', 'en' => $contactBody, 'ru' => $contactBody, 'hy' => $contactBody],
            ['slug' => 'terms', 'en' => $lorem, 'ru' => $lorem, 'hy' => $lorem],
            ['slug' => 'privacy', 'en' => $lorem, 'ru' => $lorem, 'hy' => $lorem],
            ['slug' => 'cookies', 'en' => $lorem, 'ru' => $lorem, 'hy' => $lorem],
        ];

        foreach ($seeds as $s) {
            DB::table('pages')
                ->where('page_slug', $s['slug'])
                ->update([
                    'body_html_en' => $s['en'],
                    'body_html_ru' => $s['ru'],
                    'body_html_hy' => $s['hy'],
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['body_html_en', 'body_html_ru', 'body_html_hy']);
        });
    }
};

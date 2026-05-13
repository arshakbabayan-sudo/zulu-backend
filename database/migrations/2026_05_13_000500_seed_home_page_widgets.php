<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed the public home page (pages.page_slug='home-page') with the seven
     * canonical widget_contents rows so the admin's Pages → home-page editor
     * surfaces the layout structure. Content rendering on zulu.am does NOT
     * depend on these rows — the frontend reads /api/pages/home-page joins
     * directly — but placing them here lets admins:
     *   1) see the page composition at a glance
     *   2) edit section title / subtitle copy per home-* widget
     *   3) reorder via the widget_contents.position column
     *
     * Per Phase 3 Step 3.5, the home-* widgets are mostly read-only info
     * banners for the auto-driven sections (special offers / popular
     * destinations / partners). Section title/subtitle remain editable.
     */
    public function up(): void
    {
        $page = DB::table('pages')->where('page_slug', 'home-page')->first(['id']);
        if (! $page) {
            return; // no home-page row — nothing to seed against
        }

        $now = now();

        $widgets = [
            [
                'slug' => 'home-hero',
                'ui_card_number' => 'home-page-hero',
                'position' => 1,
                'content' => [
                    'headline' => 'Find your next adventure',
                    'subheadline' => 'Flights, stays, transfers, and packages — all in one place.',
                    'background_image' => '',
                    'button_text' => 'Start exploring',
                    'button_url' => '/flights',
                ],
            ],
            [
                'slug' => 'home-special-offers',
                'ui_card_number' => 'home-page-special-offers',
                'position' => 2,
                'content' => [
                    'section_title' => 'Special offers',
                    'section_subtitle' => 'Hand-picked deals from our operators',
                ],
            ],
            [
                'slug' => 'home-popular-destinations',
                'ui_card_number' => 'home-page-popular-destinations',
                'position' => 3,
                'content' => [
                    'section_title' => 'Explore popular destinations',
                ],
            ],
            [
                'slug' => 'home-newsletter',
                'ui_card_number' => 'home-page-newsletter',
                'position' => 4,
                'content' => [
                    'title' => 'Subscribe to our newsletter',
                    'subtitle' => 'Be the first to receive exclusive offers and the latest news on our services directly in your inbox.',
                    'bg_image' => '',
                    'button_text' => 'Join us',
                ],
            ],
            [
                'slug' => 'home-partners',
                'ui_card_number' => 'home-page-partners',
                'position' => 5,
                'content' => [
                    'section_title' => 'Our partners',
                ],
            ],
            [
                'slug' => 'home-bottom-newsletter',
                'ui_card_number' => 'home-page-bottom-newsletter',
                'position' => 6,
                'content' => [
                    'title' => 'Subscribe to our newsletter',
                    'subtitle' => 'Be the first to receive exclusive offers and the latest news on our services directly in your inbox.',
                    'bg_image' => '',
                    'button_text' => 'Subscribe',
                ],
            ],
            [
                'slug' => 'home-hero-settings',
                'ui_card_number' => 'home-page-hero-settings',
                'position' => 7,
                'content' => [],
            ],
        ];

        foreach ($widgets as $w) {
            // Skip if a row for this (page_id, ui_card_number) already exists.
            $exists = DB::table('widget_contents')
                ->where('page_id', $page->id)
                ->where('ui_card_number', $w['ui_card_number'])
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('widget_contents')->insert([
                'page_id' => $page->id,
                'widget_slug' => $w['slug'],
                'ui_card_number' => $w['ui_card_number'],
                'widget_content' => json_encode($w['content']),
                'position' => $w['position'],
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $page = DB::table('pages')->where('page_slug', 'home-page')->first(['id']);
        if (! $page) {
            return;
        }

        DB::table('widget_contents')
            ->where('page_id', $page->id)
            ->whereIn('ui_card_number', [
                'home-page-hero',
                'home-page-special-offers',
                'home-page-popular-destinations',
                'home-page-newsletter',
                'home-page-partners',
                'home-page-bottom-newsletter',
                'home-page-hero-settings',
            ])
            ->delete();
    }
};

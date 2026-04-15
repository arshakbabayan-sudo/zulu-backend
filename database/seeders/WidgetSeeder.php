<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WidgetSeeder extends Seeder
{
    public function run(): void
    {
        $widgetSlugs = [
            'sliders',
            'search',
            'about-us',
            'why-choose-us',
            'fun-facts',
            'testimonials',
            'faq',
            'contact-us',
            'text-editor',
            'code-editor',
            'blogs',
            'features',
            'cta',
            'home-hero',
            'home-special-offers',
            'home-popular-destinations',
            'home-partners',
            'home-newsletter',
        ];

        $now = now();
        $rows = array_map(static function (string $slug) use ($now): array {
            return [
                'widget_name' => Str::of($slug)->replace('-', ' ')->title()->toString(),
                'widget_slug' => $slug,
                'icon' => '',
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $widgetSlugs);

        DB::table('widgets')->insertOrIgnore($rows);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convert pages.body_html_en/_ru/_hy into widget_contents (+ translations)
     * as a single code-editor widget per page. This completes the
     * widget-driven Pages CMS migration: from this point forward, admins
     * edit static page content via Pages → [page] → Code Editor widget,
     * and the frontend renders through DynamicPageRenderer.
     *
     * The body_html columns are NOT dropped here — kept as a backup
     * until visual verification confirms the migration produced the
     * intended rendering. A follow-up migration can drop them.
     */
    public function up(): void
    {
        $now = now();
        $slugs = ['about', 'contact', 'terms', 'privacy', 'cookies'];

        foreach ($slugs as $slug) {
            $page = DB::table('pages')
                ->where('page_slug', $slug)
                ->first(['id', 'body_html_en', 'body_html_ru', 'body_html_hy']);
            if (! $page) {
                continue;
            }

            // Skip if any code-editor widget already exists for this page.
            $exists = DB::table('widget_contents')
                ->where('page_id', $page->id)
                ->where('widget_slug', 'code-editor')
                ->exists();
            if ($exists) {
                continue;
            }

            $uiCardNumber = $slug.'-body';

            // Insert the canonical (EN) widget content row.
            $widgetId = DB::table('widget_contents')->insertGetId([
                'page_id' => $page->id,
                'widget_slug' => 'code-editor',
                'ui_card_number' => $uiCardNumber,
                'widget_content' => json_encode(
                    ['code' => (string) ($page->body_html_en ?? '')],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'position' => 1,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // HY + RU translations.
            foreach (['hy' => $page->body_html_hy, 'ru' => $page->body_html_ru] as $lang => $body) {
                if ($body === null) {
                    continue;
                }
                DB::table('widget_content_translations')->updateOrInsert(
                    ['widget_content_id' => $widgetId, 'lang' => $lang],
                    [
                        'page_id' => $page->id,
                        'widget_content' => json_encode(
                            ['code' => (string) $body],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ),
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        $slugs = ['about', 'contact', 'terms', 'privacy', 'cookies'];
        $pageIds = DB::table('pages')->whereIn('page_slug', $slugs)->pluck('id');

        $widgetIds = DB::table('widget_contents')
            ->whereIn('page_id', $pageIds)
            ->where('widget_slug', 'code-editor')
            ->whereIn('ui_card_number', array_map(fn ($s) => $s.'-body', $slugs))
            ->pluck('id');

        DB::table('widget_content_translations')->whereIn('widget_content_id', $widgetIds)->delete();
        DB::table('widget_contents')->whereIn('id', $widgetIds)->delete();
    }
};

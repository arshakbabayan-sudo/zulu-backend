<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convert the 5 static-page body widgets from `code-editor` to
     * `text-editor` so admins can edit the content via a WYSIWYG editor
     * rather than having to type raw HTML. The HTML payload is preserved
     * byte-for-byte (moved from `code` field to `text` field) — the
     * frontend TextWidget renders rich HTML through dangerouslySetInnerHTML.
     */
    public function up(): void
    {
        $slugs = ['about', 'contact', 'terms', 'privacy', 'cookies'];

        foreach ($slugs as $slug) {
            $pageId = DB::table('pages')->where('page_slug', $slug)->value('id');
            if (! $pageId) {
                continue;
            }

            $widget = DB::table('widget_contents')
                ->where('page_id', $pageId)
                ->where('widget_slug', 'code-editor')
                ->where('ui_card_number', $slug.'-body')
                ->first();
            if (! $widget) {
                continue;
            }

            $payload = json_decode($widget->widget_content ?? 'null', true);
            $html = is_array($payload) && isset($payload['code']) ? (string) $payload['code'] : '';

            DB::table('widget_contents')
                ->where('id', $widget->id)
                ->update([
                    'widget_slug' => 'text-editor',
                    'widget_content' => json_encode(
                        ['text' => $html],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                    'updated_at' => now(),
                ]);

            $translations = DB::table('widget_content_translations')
                ->where('widget_content_id', $widget->id)
                ->get(['id', 'widget_content']);
            foreach ($translations as $tr) {
                $tp = json_decode($tr->widget_content ?? 'null', true);
                $thtml = is_array($tp) && isset($tp['code']) ? (string) $tp['code'] : '';
                DB::table('widget_content_translations')
                    ->where('id', $tr->id)
                    ->update([
                        'widget_content' => json_encode(
                            ['text' => $thtml],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        $slugs = ['about', 'contact', 'terms', 'privacy', 'cookies'];

        foreach ($slugs as $slug) {
            $pageId = DB::table('pages')->where('page_slug', $slug)->value('id');
            if (! $pageId) {
                continue;
            }

            $widget = DB::table('widget_contents')
                ->where('page_id', $pageId)
                ->where('widget_slug', 'text-editor')
                ->where('ui_card_number', $slug.'-body')
                ->first();
            if (! $widget) {
                continue;
            }

            $payload = json_decode($widget->widget_content ?? 'null', true);
            $html = is_array($payload) && isset($payload['text']) ? (string) $payload['text'] : '';

            DB::table('widget_contents')
                ->where('id', $widget->id)
                ->update([
                    'widget_slug' => 'code-editor',
                    'widget_content' => json_encode(
                        ['code' => $html],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                    'updated_at' => now(),
                ]);

            $translations = DB::table('widget_content_translations')
                ->where('widget_content_id', $widget->id)
                ->get(['id', 'widget_content']);
            foreach ($translations as $tr) {
                $tp = json_decode($tr->widget_content ?? 'null', true);
                $thtml = is_array($tp) && isset($tp['text']) ? (string) $tp['text'] : '';
                DB::table('widget_content_translations')
                    ->where('id', $tr->id)
                    ->update([
                        'widget_content' => json_encode(
                            ['code' => $thtml],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ),
                        'updated_at' => now(),
                    ]);
            }
        }
    }
};

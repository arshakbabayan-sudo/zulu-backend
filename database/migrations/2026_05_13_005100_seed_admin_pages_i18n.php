<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Translations for the admin Pages section (list + visual editor).
     *
     * Triggered by user screenshots showing the editor with EN/RU/HY
     * language tabs but every form label still hardcoded in English.
     */
    public function up(): void
    {
        $now = now();

        // [key, en, ru, hy]
        $rows = [
            // List screen
            ['admin.pages.title', 'Pages', 'Страницы', 'Կայքի էջեր'],
            ['admin.pages.add_new', 'Add New Page', 'Добавить страницу', 'Ավելացնել նոր էջ'],
            ['admin.pages.col.sn', 'S.N', '№', '№'],
            ['admin.pages.col.name', 'Name', 'Название', 'Անվանում'],
            ['admin.pages.col.status', 'Status', 'Статус', 'Կարգավիճակ'],
            ['admin.pages.col.published', 'Published', 'Опубликовано', 'Հրապարակված'],
            ['admin.pages.col.created', 'Created', 'Создано', 'Ստեղծված'],
            ['admin.pages.col.actions', 'Actions', 'Действия', 'Գործողություններ'],
            ['admin.pages.action.view', 'View', 'Просмотр', 'Դիտել'],
            ['admin.pages.action.edit', 'Edit', 'Изменить', 'Խմբագրել'],
            ['admin.pages.action.delete', 'Delete', 'Удалить', 'Ջնջել'],
            ['admin.pages.status.active', 'Active', 'Активна', 'Ակտիվ'],
            ['admin.pages.status.inactive', 'Inactive', 'Неактивна', 'Անակտիվ'],
            ['admin.pages.empty', 'No pages found.', 'Страницы не найдены.', 'Էջեր չեն գտնվել։'],
            ['admin.pages.confirm_delete', 'Delete page "{name}"?', 'Удалить страницу "{name}"?', 'Ջնջե՞լ «{name}» էջը։'],
            ['admin.pages.back_to_pages', '← Back to Pages', '← Назад к страницам', '← Վերադարձ էջերին'],

            // Visual editor
            ['admin.pages.editor.title', 'Page Visual Editor', 'Визуальный редактор страницы', 'Էջի վիզուալ խմբագիր'],
            ['admin.pages.editor.editing', 'Editing', 'Редактирование', 'Խմբագրվում է'],
            ['admin.pages.editor.copy_default', 'Copy page fields from default', 'Скопировать поля из основного', 'Պատճենել հիմնական լեզվից'],
            ['admin.pages.editor.menu_name', 'Menu Name', 'Название меню', 'Մենյուի անվանում'],
            ['admin.pages.editor.slug_name', 'Slug Name', 'URL-ի slug', 'URL-ի slug'],
            ['admin.pages.editor.meta_title', 'Meta Title', 'Meta заголовок', 'Meta վերնագիր'],
            ['admin.pages.editor.meta_keywords', 'Meta Keywords (comma separated)', 'Meta ключевые слова (через запятую)', 'Meta բանալի բառեր (ստորակետով անջատված)'],
            ['admin.pages.editor.meta_description', 'Meta Description', 'Meta описание', 'Meta նկարագրություն'],
            ['admin.pages.editor.allow_seo', 'Allow Page SEO', 'Разрешить SEO для страницы', 'Թույլատրել SEO էջի համար'],
            ['admin.pages.editor.breadcrumb', 'Bread Crumb Enable', 'Включить хлебные крошки', 'Միացնել նավիգացիոն շղթան'],
            ['admin.pages.editor.published_btn', 'Published', 'Опубликовано', 'Հրապարակված'],
            ['admin.pages.editor.draft_btn', 'Draft', 'Черновик', 'Նախագիծ'],
            ['admin.pages.editor.view_page', 'View Page', 'Просмотр страницы', 'Դիտել էջը'],

            // Add-page modal
            ['admin.pages.modal.title', 'Add New Page', 'Добавление страницы', 'Նոր էջի ավելացում'],
            ['admin.pages.modal.name_label', 'Menu name', 'Название меню', 'Մենյուի անվանում'],
            ['admin.pages.modal.slug_label', 'URL slug', 'URL slug', 'URL slug'],
            ['admin.pages.modal.create', 'Create', 'Создать', 'Ստեղծել'],
            ['admin.pages.modal.cancel', 'Cancel', 'Отмена', 'Չեղարկել'],
            ['admin.pages.error.update_status', 'Failed to update status', 'Не удалось обновить статус', 'Չհաջողվեց թարմացնել կարգավիճակը'],

            // Static-pages editor (Sprint 3 TipTap) — back link reuses pages.back_to_pages
            ['admin.static_pages.editor.title', 'Edit page', 'Редактирование страницы', 'Էջի խմբագրում'],
            ['admin.static_pages.editor.meta_section', 'Page meta', 'Метаданные страницы', 'Էջի մետատվյալներ'],
            ['admin.static_pages.editor.display_name', 'Display name (internal)', 'Внутреннее название', 'Ներքին անվանում'],
            ['admin.static_pages.editor.seo_title', 'SEO title', 'SEO заголовок', 'SEO վերնագիր'],
            ['admin.static_pages.editor.seo_description', 'SEO description', 'SEO описание', 'SEO նկարագրություն'],
            ['admin.static_pages.editor.body_section', 'Body content', 'Содержимое', 'Բովանդակություն'],
            ['admin.static_pages.editor.save', 'Save all', 'Сохранить', 'Պահպանել'],
            ['admin.static_pages.editor.saving', 'Saving…', 'Сохранение…', 'Պահպանվում է…'],
            ['admin.static_pages.editor.saved', 'Saved.', 'Сохранено.', 'Պահպանվեց։'],
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
        DB::table('ui_translations')
            ->where(function ($q) {
                $q->where('key', 'like', 'admin.pages.%')
                    ->orWhere('key', 'like', 'admin.static_pages.%');
            })
            ->delete();
        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

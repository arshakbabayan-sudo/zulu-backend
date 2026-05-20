<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Final batch of translation keys for the admin <ConfirmDialog> primitive.
 * Covers the last 2 dynamic-message window.confirm() call sites in the
 * admin pages list + page editor (delete page / delete widget).
 *
 * Companion of 2026_05_20_150000_seed_confirm_dialog_translations.php.
 * After deploy, clear the ui_translations cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['admin.pages.confirm_delete', 'en', 'Delete page "{name}"?'],
            ['admin.pages.confirm_delete', 'hy', 'Ջնջե՞լ «{name}» էջը'],
            ['admin.pages.confirm_delete', 'ru', 'Удалить страницу «{name}»?'],

            ['admin.pages.confirm_delete_widget', 'en', 'Delete "{label}" widget?'],
            ['admin.pages.confirm_delete_widget', 'hy', 'Ջնջե՞լ «{label}» վիջեթը'],
            ['admin.pages.confirm_delete_widget', 'ru', 'Удалить виджет «{label}»?'],
        ];

        $now = now();
        foreach ($rows as [$key, $lang, $value]) {
            DB::table('ui_translations')->updateOrInsert(
                ['key' => $key, 'language_code' => $lang],
                ['value' => $value, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->whereIn('key', [
                'admin.pages.confirm_delete',
                'admin.pages.confirm_delete_widget',
            ])
            ->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the missing translation keys for the admin login / forgot-password /
 * reset-password pages. Discovered during the 2026-05-20 platform audit:
 *
 *   - "Operations console — internal access only" (tagline)  → 3 pages
 *   - "Remember me"        (login checkbox label)
 *   - "Forgot password?"   (login link)
 *
 * All were hardcoded English literals; non-English users saw them in English
 * regardless of locale. Clear `ui_translations_<lang>` cache after deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['admin.login.tagline', 'en', 'Operations console — internal access only'],
            ['admin.login.tagline', 'hy', 'Գործառնական վահանակ — միայն ներքին մուտք'],
            ['admin.login.tagline', 'ru', 'Операционная консоль — только внутренний доступ'],

            ['admin.login.remember_me', 'en', 'Remember me'],
            ['admin.login.remember_me', 'hy', 'Հիշիր ինձ'],
            ['admin.login.remember_me', 'ru', 'Запомнить меня'],

            ['admin.login.forgot_password_link', 'en', 'Forgot password?'],
            ['admin.login.forgot_password_link', 'hy', 'Մոռացե՞լ եք գաղտնաբառը'],
            ['admin.login.forgot_password_link', 'ru', 'Забыли пароль?'],
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
                'admin.login.tagline',
                'admin.login.remember_me',
                'admin.login.forgot_password_link',
            ])
            ->delete();
    }
};

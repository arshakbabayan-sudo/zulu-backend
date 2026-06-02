<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * P0-2 cleanup — collapse the two role-specific "Apply as agent" / "Apply as
 * operator" links on /login + /register into one neutral "Join as a company"
 * button. Role pick now lives inside CompanyApplyForm as a Tour agent / Tour
 * operator pill toggle, so we also need the pill labels.
 *
 * - auth.login_join_as_company — login + register card CTA
 * - company.apply.role_picker_agent / _operator — pill labels on the form
 *
 * EN mirrors zulu-frontend-next/lib/lang.ts bundled defaults; HY + RU are real
 * translations (no Cyrillic in the Armenian column — checked).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            'auth.login_join_as_company' => [
                'en' => 'Join as a company',
                'hy' => 'Միանալ որպես ընկերություն',
                'ru' => 'Стать партнёром',
            ],
            'company.apply.role_picker_agent' => [
                'en' => 'Tour agent',
                'hy' => 'Տուր գործակալ',
                'ru' => 'Турагент',
            ],
            'company.apply.role_picker_operator' => [
                'en' => 'Tour operator',
                'hy' => 'Տուր օպերատոր',
                'ru' => 'Туроператор',
            ],
        ];

        $now = now();
        foreach ($rows as $key => $byLang) {
            foreach ($byLang as $lang => $value) {
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
            ->whereIn('key', [
                'auth.login_join_as_company',
                'company.apply.role_picker_agent',
                'company.apply.role_picker_operator',
            ])
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();
        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

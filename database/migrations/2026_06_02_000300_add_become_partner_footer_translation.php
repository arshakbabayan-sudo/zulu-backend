<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * P0-2 cleanup follow-up — Arshak 2026-06-02. A signed-in B2C visitor should
 * be able to apply to become an operator/agent without leaving the site, so
 * we surface a "Become a partner" CTA in the public footer. The link goes to
 * /companies/apply, where CompanyApplyForm now auto-prefills business_email,
 * contact_person and phone from the logged-in account.
 *
 * Adds one key: footer.become_partner (EN/HY/RU). Verified no Cyrillic in
 * the Armenian column.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            'footer.become_partner' => [
                'en' => 'Become a partner',
                'hy' => 'Դառնալ գործընկեր',
                'ru' => 'Стать партнёром',
            ],
        ];

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
            ->where('key', 'footer.become_partner')
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();
        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

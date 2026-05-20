<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3.4 GDPR — seeds translation keys for the registration age-gate
 * (GDPR Article 8 — under-16 self-attestation).
 *
 * Keys consumed by zulu-frontend-next/components/auth/RegisterForm.tsx:
 *  - auth.register_age_confirm   (checkbox label)
 *  - auth.register_age_required  (validation error if unchecked)
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['auth.register_age_confirm', 'en', 'I confirm I am at least 16 years old.'],
            ['auth.register_age_confirm', 'hy', 'Հաստատում եմ, որ ես առնվազն 16 տարեկան եմ։'],
            ['auth.register_age_confirm', 'ru', 'Подтверждаю, что мне не менее 16 лет.'],

            ['auth.register_age_required', 'en', 'You must confirm you are at least 16 years old to continue.'],
            ['auth.register_age_required', 'hy', 'Շարունակելու համար պետք է հաստատեք, որ առնվազն 16 տարեկան եք։'],
            ['auth.register_age_required', 'ru', 'Для продолжения подтвердите, что вам не менее 16 лет.'],
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
            ->whereIn('key', ['auth.register_age_confirm', 'auth.register_age_required'])
            ->delete();
    }
};

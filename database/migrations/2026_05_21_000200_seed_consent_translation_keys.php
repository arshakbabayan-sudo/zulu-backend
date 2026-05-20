<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the translation key for the registration consent error message.
 *
 * Phase 1.2 GDPR Critical fix introduced an explicit consent checkbox on the
 * customer site's RegisterForm. If a user submits without checking the box,
 * the form surfaces `t("auth.register_terms_required")` — which was missing
 * from ui_translations and would have rendered as the raw key.
 *
 * After deploy: `php artisan cache:forget ui_translations_{en,hy,ru}` runs
 * automatically as part of the deploy hook (php artisan optimize:clear).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['auth.register_terms_required', 'en', 'You must agree to the Terms and Privacy Policy to continue.'],
            ['auth.register_terms_required', 'hy', 'Շարունակելու համար պետք է համաձայնեք Պայմաններին և Գաղտնիության քաղաքականությանը։'],
            ['auth.register_terms_required', 'ru', 'Для продолжения необходимо согласиться с Условиями и Политикой конфиденциальности.'],
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
            ->where('key', 'auth.register_terms_required')
            ->delete();
    }
};

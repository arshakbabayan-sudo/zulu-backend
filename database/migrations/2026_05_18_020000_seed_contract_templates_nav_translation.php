<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5b — seeds the `admin.nav.tab.contract_templates` sidebar label
     * in EN/HY/RU. Used by the new /platform/contract-templates list and
     * editor pages added in the companion admin-next commit.
     *
     * Separate from the Phase 5a contracts key (which was already deployed
     * via 2026_05_18_010000); this migration adds only the new templates row.
     */
    public function up(): void
    {
        $rows = [
            ['admin.nav.tab.contract_templates', 'en', 'Contract templates'],
            ['admin.nav.tab.contract_templates', 'hy', 'Պայմանագրի ձևանմուշներ'],
            ['admin.nav.tab.contract_templates', 'ru', 'Шаблоны договоров'],
        ];

        foreach ($rows as [$key, $lang, $value]) {
            DB::table('ui_translations')->updateOrInsert(
                ['key' => $key, 'language_code' => $lang],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->where('key', 'admin.nav.tab.contract_templates')
            ->whereIn('language_code', ['en', 'hy', 'ru'])
            ->delete();

        Cache::forget('ui_translations_en');
        Cache::forget('ui_translations_hy');
        Cache::forget('ui_translations_ru');
    }
};

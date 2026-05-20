<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds nav-label translations for three previously hidden admin pages
 * that are now wired into the sidebar:
 *
 *   /platform/packages   → bucket3 group, "Packages oversight"
 *   /platform/vouchers   → finance group, "Vouchers"
 *   /agent/contracts     → new agent_tools group, "My contracts"
 *
 * Pattern matches `2026_05_12_001000_add_admin_nav_grouped_translations.php`
 * (raw inserts into `ui_translations` keyed by language_code).
 *
 * Remember to run `php artisan cache:forget ui_translations_<lang>` (or
 * `cache:clear`) after this migration so the rendered admin picks up
 * the new strings instead of showing raw keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            // /platform/packages — super-admin oversight (distinct from /operator/packages)
            ['admin.nav.tab.packages_oversight', 'en', 'Packages oversight'],
            ['admin.nav.tab.packages_oversight', 'hy', 'Փաթեթների վերահսկում'],
            ['admin.nav.tab.packages_oversight', 'ru', 'Контроль пакетов'],

            // /platform/vouchers — finance voucher viewer
            ['admin.nav.tab.vouchers', 'en', 'Vouchers'],
            ['admin.nav.tab.vouchers', 'hy', 'Վաուչերներ'],
            ['admin.nav.tab.vouchers', 'ru', 'Ваучеры'],

            // New "Agent tools" sidebar group (visible only to users with the agent role)
            ['admin.nav.group.agent_tools', 'en', 'Agent tools'],
            ['admin.nav.group.agent_tools', 'hy', 'Գործակալի գործիքներ'],
            ['admin.nav.group.agent_tools', 'ru', 'Инструменты агента'],

            // /agent/contracts — single tab inside the agent_tools group
            ['admin.nav.tab.my_contracts', 'en', 'My contracts'],
            ['admin.nav.tab.my_contracts', 'hy', 'Իմ պայմանագրերը'],
            ['admin.nav.tab.my_contracts', 'ru', 'Мои договоры'],
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
                'admin.nav.tab.packages_oversight',
                'admin.nav.tab.vouchers',
                'admin.nav.group.agent_tools',
                'admin.nav.tab.my_contracts',
            ])
            ->delete();
    }
};

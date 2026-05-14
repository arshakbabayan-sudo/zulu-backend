<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Restore the Booking and Code check header menu items that the
     * 2026_05_14_000200 cleanup migration removed. The previous cleanup
     * deleted the rows because their URLs 404'd, but the user wanted the
     * labels kept and the URLs fixed instead. Repoint both to /account/trips,
     * matching the static fallback in zulu-frontend-next/lib/nav.ts.
     *
     * Idempotent — uses updateOrInsert on (url, parent_id).
     */
    public function up(): void
    {
        $now = now();

        // Make sure positions stay sane around the existing About / Destinations / Help rows.
        // Existing rows after the cleanup were at positions 1 (About) / 2 (Destinations) / 3 (Help).
        // Restore the full Figma order: About / Booking / Destinations / Code check / Help.
        DB::table('header_menu_items')
            ->where('label_en', 'Destinations')
            ->whereNull('parent_id')
            ->update(['position' => 3, 'updated_at' => $now]);

        DB::table('header_menu_items')
            ->where('label_en', 'Help')
            ->whereNull('parent_id')
            ->update(['position' => 5, 'updated_at' => $now]);

        $rows = [
            [
                'label_en' => 'Booking', 'label_ru' => 'Бронирование', 'label_hy' => 'Ամրագրում',
                'url' => '/account/trips', 'position' => 2,
            ],
            [
                'label_en' => 'Code check', 'label_ru' => 'Проверка кода', 'label_hy' => 'Կոդի ստուգում',
                'url' => '/account/vouchers', 'position' => 4,
            ],
        ];

        foreach ($rows as $row) {
            DB::table('header_menu_items')->updateOrInsert(
                ['label_en' => $row['label_en'], 'parent_id' => null],
                array_merge($row, [
                    'parent_id' => null,
                    'is_visible' => true,
                    'open_in_new_tab' => false,
                    'icon' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ])
            );
        }
    }

    public function down(): void
    {
        // No-op — going back would re-introduce the missing rows.
    }
};

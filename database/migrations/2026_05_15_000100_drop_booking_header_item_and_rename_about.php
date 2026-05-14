<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Per product owner (2026-05-15):
     *  - Remove the "Booking" header menu item (top-level, parent_id IS NULL).
     *    The /account/trips link is still reachable via the user dropdown,
     *    but should not occupy a primary header slot.
     *  - Rename the EN label "About" → "About us" so it matches the footer
     *    column and the wider product copy. HY (Մեր մասին) and RU (О нас)
     *    already render as "About us", left unchanged.
     */
    public function up(): void
    {
        $now = now();

        DB::table('header_menu_items')
            ->whereNull('parent_id')
            ->where('label_en', 'Booking')
            ->delete();

        DB::table('header_menu_items')
            ->whereNull('parent_id')
            ->where('label_en', 'About')
            ->update([
                'label_en' => 'About us',
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        $now = now();

        DB::table('header_menu_items')
            ->whereNull('parent_id')
            ->where('label_en', 'About us')
            ->update([
                'label_en' => 'About',
                'updated_at' => $now,
            ]);

        DB::table('header_menu_items')->updateOrInsert(
            ['label_en' => 'Booking', 'parent_id' => null],
            [
                'label_ru' => 'Бронирование',
                'label_hy' => 'Ամրագրում',
                'url' => '/account/trips',
                'position' => 2,
                'parent_id' => null,
                'is_visible' => true,
                'open_in_new_tab' => false,
                'icon' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }
};

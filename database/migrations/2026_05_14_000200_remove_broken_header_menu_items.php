<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remove header menu items whose URLs point to pages that don't exist.
     * The 2026_05_13_002200_seed_initial_header_footer migration introduced
     * /bookings and /code-check rows; neither has a backing route, so Next.js
     * prefetches 404 on every page load and the rendered nav clutters the
     * header with non-functional links.
     */
    public function up(): void
    {
        DB::table('header_menu_items')
            ->whereIn('url', ['/bookings', '/code-check'])
            ->whereNull('parent_id')
            ->delete();
    }

    public function down(): void
    {
        // No-op — reintroducing broken links would re-create the bug.
    }
};

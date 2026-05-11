<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Patch the 3 broken Unsplash URLs from the package seed
     * (2026_05_11_000600). Same fix pattern as commit 582756b for
     * cars/excursions: replace the 404-returning photo IDs with
     * Unsplash photos verified to return HTTP 200.
     */
    public function up(): void
    {
        $updates = [
            'Yerevan Weekend Discovery · 3 days' => 'https://images.unsplash.com/photo-1551966775-a4ddc8df052b?auto=format&fit=crop&w=1200&q=80',
            'Armenia Highlights · 5 days' => 'https://images.unsplash.com/photo-1576073719676-aa95576db207?auto=format&fit=crop&w=1200&q=80',
            'Wine & Wonders · 7 days' => 'https://images.unsplash.com/photo-1542314831-068cd1dbfeeb?auto=format&fit=crop&w=1200&q=80',
        ];

        foreach ($updates as $title => $url) {
            DB::table('packages')->where('package_title', $title)->update(['main_image' => $url]);
            $offerId = DB::table('offers')->where('type', 'package')->where('title', $title)->value('id');
            if ($offerId) {
                // No main_image column on offers itself — keep package row patched.
            }
        }
    }

    public function down(): void
    {
        // No-op: the broken URLs the original seed shipped with are not worth restoring.
    }
};

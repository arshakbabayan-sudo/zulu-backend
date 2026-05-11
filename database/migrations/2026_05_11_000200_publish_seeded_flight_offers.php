<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The 2026_05_11_000100 seed inserted offers with status='active', but
     * DiscoveryService only surfaces 'published' offers on the public site.
     * Flip the ten seeded flights to 'published' so they appear on
     * zulu.am/flights without operator action.
     */
    public function up(): void
    {
        $codes = [
            'SU1869', 'SU1870',
            '3F721', '3F722',
            'W6362', 'W6363',
            'W64101', 'W64102',
            '3F505', '3F506',
        ];

        $offerIds = DB::table('flights')
            ->whereIn('flight_code_internal', $codes)
            ->pluck('offer_id')
            ->all();

        if (! empty($offerIds)) {
            DB::table('offers')
                ->whereIn('id', $offerIds)
                ->where('status', 'active')
                ->update([
                    'status' => 'published',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        $codes = [
            'SU1869', 'SU1870',
            '3F721', '3F722',
            'W6362', 'W6363',
            'W64101', 'W64102',
            '3F505', '3F506',
        ];

        $offerIds = DB::table('flights')
            ->whereIn('flight_code_internal', $codes)
            ->pluck('offer_id')
            ->all();

        if (! empty($offerIds)) {
            DB::table('offers')
                ->whereIn('id', $offerIds)
                ->where('status', 'published')
                ->update([
                    'status' => 'active',
                    'updated_at' => now(),
                ]);
        }
    }
};

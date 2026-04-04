<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->insertOrIgnore([
            [
                'key' => 'hotel_visibility_controls_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'description' => 'Enable HOTEL visibility_rule enforcement (web + zulu-admin inventory).',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'hotel_service_connections_integration_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'description' => 'Enable HOTEL targeting enrichment from accepted service_connections during discovery (hotel module).',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('platform_settings')->whereIn('key', [
            'hotel_visibility_controls_enabled',
            'hotel_service_connections_integration_enabled',
        ])->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->insertOrIgnore([
            [
                'key' => 'excursion_visibility_controls_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'description' => 'Enable EXCURSION visibility_rule + appearance flags (web catalog + zulu-admin inventory + operator listings).',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'excursion_service_connections_integration_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'description' => 'Enable EXCURSION targeting from accepted service_connections during discovery (excursion module when from/to are set).',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('platform_settings')->whereIn('key', [
            'excursion_visibility_controls_enabled',
            'excursion_service_connections_integration_enabled',
        ])->delete();
    }
};

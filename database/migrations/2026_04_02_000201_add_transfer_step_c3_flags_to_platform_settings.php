<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->insertOrIgnore([
            [
                'key' => 'transfer_visibility_controls_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'description' => 'Enable TRANSFER visibility_rule enforcement (web + zulu-admin inventory).',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'transfer_service_connections_integration_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'description' => 'Enable TRANSFER targeting enrichment from accepted service_connections during discovery (transfer module).',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('platform_settings')->whereIn('key', [
            'transfer_visibility_controls_enabled',
            'transfer_service_connections_integration_enabled',
        ])->delete();
    }
};

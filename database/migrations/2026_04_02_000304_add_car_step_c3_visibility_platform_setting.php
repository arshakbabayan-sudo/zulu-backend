<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->insertOrIgnore([
            [
                'key' => 'car_visibility_controls_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'description' => 'Enable CAR rental visibility_rule + appearance flags (web + zulu-admin inventory).',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('platform_settings')->where('key', 'car_visibility_controls_enabled')->delete();
    }
};

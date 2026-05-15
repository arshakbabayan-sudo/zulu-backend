<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revert of 2026_05_15_001000_set_armenian_as_default_language.
 *
 * Per product owner direction (2026-05-15): EN remains the global default
 * language. Per-country defaults (HY for Armenia, RU for Russia) are
 * handled by geo-IP detection at the request edge, not the database
 * default flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supported_languages')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('supported_languages')->update(['is_default' => false]);
            DB::table('supported_languages')->where('code', 'en')->update(['is_default' => true]);
        });

        Cache::forget('localization_default_language_code');
        Cache::forget('localization_enabled_language_map');
    }

    public function down(): void
    {
        if (! Schema::hasTable('supported_languages')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('supported_languages')->update(['is_default' => false]);
            DB::table('supported_languages')->where('code', 'hy')->update(['is_default' => true]);
        });

        Cache::forget('localization_default_language_code');
        Cache::forget('localization_enabled_language_map');
    }
};

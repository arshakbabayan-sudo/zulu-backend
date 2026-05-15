<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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

    public function down(): void
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
};

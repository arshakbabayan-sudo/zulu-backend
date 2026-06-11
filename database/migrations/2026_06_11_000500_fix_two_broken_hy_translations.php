<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fix two garbled Armenian rows spotted live on zulu.am/visa (2026-06-11):
 *   common.male       hy  «Տղամեր»     → «Տղամարդ»
 *   visa.type_tourist hy  «Զբոսաշրոմ»  (Cyrillic-contaminated tail) → «Զբոսաշրջային»
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('ui_translations')->upsert([
            ['language_code' => 'hy', 'key' => 'common.male', 'value' => 'Տղամարդ', 'created_at' => $now, 'updated_at' => $now],
            ['language_code' => 'hy', 'key' => 'visa.type_tourist', 'value' => 'Զբոսաշրջային', 'created_at' => $now, 'updated_at' => $now],
        ], ['language_code', 'key'], ['value', 'updated_at']);

        Cache::forget('ui_translations_hy');
    }

    public function down(): void
    {
        // The old values were typos — nothing to restore.
    }
};

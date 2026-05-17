<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B foundation — equalize all languages, drop the EN-as-base privilege.
 *
 * 1) content_translations gets `is_manually_edited` (bool) and
 *    `translation_status` (enum: manual | ai_pending | ai_completed | ai_failed)
 *    so the AI translator knows which rows it must not overwrite.
 *
 * 2) Every translatable entity table gets a `source_lang` column that records
 *    the language the operator originally chose when creating the row. The AI
 *    translator translates *from* this language into every other enabled
 *    language. Existing rows default to 'en' (legacy) and are corrected by the
 *    next backfill migration if a non-EN base value exists.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $entityTables = [
        'hotels',
        'excursions',
        'transfers',
        'cars',
        'visas',
        'packages',
        'flights',
        'offers',
        'companies',
    ];

    public function up(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_translations', 'is_manually_edited')) {
                $table->boolean('is_manually_edited')->default(false)->after('translated_value');
            }
            if (! Schema::hasColumn('content_translations', 'translation_status')) {
                $table->string('translation_status', 16)->default('manual')->after('is_manually_edited');
            }
        });

        foreach ($this->entityTables as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }
            if (Schema::hasColumn($name, 'source_lang')) {
                continue;
            }
            Schema::table($name, function (Blueprint $table): void {
                $table->string('source_lang', 8)->default('en')->after('id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            if (Schema::hasColumn('content_translations', 'translation_status')) {
                $table->dropColumn('translation_status');
            }
            if (Schema::hasColumn('content_translations', 'is_manually_edited')) {
                $table->dropColumn('is_manually_edited');
            }
        });

        foreach ($this->entityTables as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }
            if (! Schema::hasColumn($name, 'source_lang')) {
                continue;
            }
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('source_lang');
            });
        }
    }
};

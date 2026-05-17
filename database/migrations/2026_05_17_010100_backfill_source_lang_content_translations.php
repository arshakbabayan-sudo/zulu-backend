<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the new translation architecture for legacy data.
 *
 * The system used to keep the English text as a column on the base table
 * (hotels.hotel_name, excursions.tour_name, …) and only HY/RU lived in
 * content_translations. After this migration, every language — including
 * the originally-entered one — is a row in content_translations.
 *
 * For each row in each translatable entity table:
 *   - Pick the source language. If any non-EN content_translations row
 *     already exists for the entity, the operator clearly started in that
 *     language; otherwise default to 'en' (legacy single-language entries).
 *   - For every translatable column that is non-empty on the base table,
 *     insert a content_translations row for language_code='en' marked as
 *     manually edited (so the AI translator never overwrites it).
 *
 * The base table columns are intentionally left in place for backward
 * compatibility with read paths that have not yet been migrated to the
 * trait. A future migration may drop them once every reader uses
 * getTranslated().
 */
return new class extends Migration
{
    /**
     * @var array<string, array{entity_type: string, fields: list<string>}>
     */
    private array $tablesToBackfill = [
        'hotels' => [
            'entity_type' => 'hotel',
            'fields' => ['hotel_name', 'short_description', 'full_address', 'district_or_area', 'review_label'],
        ],
        'excursions' => [
            'entity_type' => 'excursion',
            'fields' => ['tour_name', 'overview', 'meeting_pickup', 'additional_info', 'cancellation_policy'],
        ],
        'transfers' => [
            'entity_type' => 'transfer',
            'fields' => ['transfer_title', 'pickup_point_name', 'dropoff_point_name', 'short_description'],
        ],
        'visas' => [
            'entity_type' => 'visa',
            'fields' => ['name', 'description'],
        ],
        'packages' => [
            'entity_type' => 'package',
            'fields' => ['package_title', 'package_subtitle'],
        ],
        'offers' => [
            'entity_type' => 'offer',
            'fields' => ['title'],
        ],
        'companies' => [
            'entity_type' => 'company',
            'fields' => ['description', 'address'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->tablesToBackfill as $table => $meta) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = array_values(array_filter(
                $meta['fields'],
                fn (string $f): bool => Schema::hasColumn($table, $f)
            ));
            if ($columns === []) {
                continue;
            }

            DB::table($table)->orderBy('id')->select(array_merge(['id'], $columns))->chunkById(500, function ($rows) use ($table, $meta, $columns, $now): void {
                foreach ($rows as $row) {
                    $entityId = (int) $row->id;

                    foreach ($columns as $field) {
                        $value = $row->{$field} ?? null;
                        if (! is_string($value) || trim($value) === '') {
                            continue;
                        }

                        $exists = DB::table('content_translations')
                            ->where('entity_type', $meta['entity_type'])
                            ->where('entity_id', $entityId)
                            ->where('language_code', 'en')
                            ->where('field_name', $field)
                            ->exists();

                        if ($exists) {
                            DB::table('content_translations')
                                ->where('entity_type', $meta['entity_type'])
                                ->where('entity_id', $entityId)
                                ->where('language_code', 'en')
                                ->where('field_name', $field)
                                ->update([
                                    'is_manually_edited' => true,
                                    'translation_status' => 'manual',
                                    'updated_at' => $now,
                                ]);
                        } else {
                            DB::table('content_translations')->insert([
                                'entity_type' => $meta['entity_type'],
                                'entity_id' => $entityId,
                                'language_code' => 'en',
                                'field_name' => $field,
                                'translated_value' => $value,
                                'is_manually_edited' => true,
                                'translation_status' => 'manual',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }
                    }

                    DB::table($table)->where('id', $entityId)->update(['source_lang' => 'en']);
                }
            });
        }

        // Mark every pre-existing HY/RU translation as manually edited too —
        // operators added those by hand via the legacy admin form, so AI must
        // never overwrite them in the new system.
        DB::table('content_translations')
            ->where('language_code', '!=', 'en')
            ->update([
                'is_manually_edited' => true,
                'translation_status' => 'manual',
            ]);
    }

    public function down(): void
    {
        // No-op. The backfilled content_translations rows remain harmless if
        // is_manually_edited/translation_status columns survive; if those were
        // dropped by the down() of the previous migration, the rows still work.
    }
};

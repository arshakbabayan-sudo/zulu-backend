<?php

namespace App\Traits;

use App\Jobs\TranslateContentJob;
use App\Models\ContentTranslation;
use App\Models\SupportedLanguage;
use App\Services\Localization\LocalizationService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Equal-language translation system.
 *
 * Every translatable entity tracks a `source_lang` column — the language the
 * operator chose when they first added the record. All language versions
 * (including the source) live as rows in content_translations; no language
 * is "the base." Translations into other languages are produced by the AI
 * translator, which never overwrites a row flagged is_manually_edited=true.
 *
 * The legacy base columns (e.g. hotels.hotel_name) are kept ONLY as a
 * denormalized safety-net mirror of the source-language value, so any old
 * read path still returns something. New read paths use {@see getTranslated()}
 * which never touches the base column unless every translation row is
 * missing.
 */
trait HasTranslations
{
    public function getTranslatableEntityType(): string
    {
        return strtolower(class_basename($this));
    }

    /**
     * @return list<string>
     */
    public function getTranslatableFields(): array
    {
        if (property_exists($this, 'translatableFields') && is_array($this->translatableFields)) {
            return array_values(array_filter(
                $this->translatableFields,
                fn ($f): bool => is_string($f)
                    && in_array($f, ContentTranslation::TRANSLATABLE_FIELDS, true)
            ));
        }

        return [];
    }

    /**
     * The language the operator originally entered this record in. Returns
     * the entity's `source_lang` column if present, falling back to the
     * platform default language. Reading is safe before the schema migration
     * has run (returns the default lang).
     */
    public function getSourceLanguage(): string
    {
        $column = $this->attributes['source_lang'] ?? null;
        if (is_string($column) && $column !== '') {
            return $column;
        }

        return $this->resolveDefaultLanguageCode();
    }

    /**
     * @return HasMany<ContentTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ContentTranslation::class, 'entity_id')
            ->where('entity_type', $this->getTranslatableEntityType());
    }

    /**
     * Return the translated value for $field in $languageCode using a five-step
     * fallback. Every translation lives in content_translations; the base
     * table column is only consulted if every translation row is missing.
     *
     * Resolution order (first non-empty wins):
     *   1. content_translations row for the requested $languageCode
     *   2. content_translations row for the entity's source_lang
     *      (this is the "Translation in progress" path on the customer site)
     *   3. content_translations row for the platform default language
     *   4. base table column value (legacy safety net)
     *   5. caller-supplied $fallback (defaults to null)
     */
    public function getTranslated(string $field, string $languageCode, ?string $fallback = null): ?string
    {
        $base = $this->attributes[$field] ?? null;
        $base = is_string($base) && trim($base) !== '' ? (string) $base : null;
        $effectiveFallback = $fallback ?? $base;

        if ($this->relationLoaded('translations')) {
            $rows = $this->translations->where('field_name', $field);

            $value = $rows->firstWhere('language_code', $languageCode)?->translated_value;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }

            $sourceLang = $this->getSourceLanguage();
            if ($sourceLang !== $languageCode) {
                $value = $rows->firstWhere('language_code', $sourceLang)?->translated_value;
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }

            $defaultLang = $this->resolveDefaultLanguageCode();
            if ($defaultLang !== $languageCode && $defaultLang !== $sourceLang) {
                $value = $rows->firstWhere('language_code', $defaultLang)?->translated_value;
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }

            return $effectiveFallback;
        }

        return app(LocalizationService::class)->getTranslation(
            $this->getTranslatableEntityType(),
            (int) $this->getKey(),
            $field,
            $languageCode,
            $effectiveFallback,
            $this->getSourceLanguage()
        );
    }

    /**
     * Same as {@see getTranslated()} but reports which step produced the value.
     *
     * The `source` value is what the customer site uses to decide whether to
     * render a "🔄 Translation in progress" banner: anything other than
     * 'translated' means the user requested a language that isn't yet ready.
     *
     * @return array{value: ?string, source: 'translated'|'source_lang'|'default_lang'|'base_table'|'missing', is_manually_edited: bool}
     */
    public function getTranslationSource(string $field, string $languageCode): array
    {
        $base = $this->attributes[$field] ?? null;
        $base = is_string($base) && trim($base) !== '' ? (string) $base : null;

        if ($this->relationLoaded('translations')) {
            $rows = $this->translations->where('field_name', $field);

            $primary = $rows->firstWhere('language_code', $languageCode);
            if ($primary !== null && is_string($primary->translated_value) && trim($primary->translated_value) !== '') {
                return [
                    'value' => (string) $primary->translated_value,
                    'source' => 'translated',
                    'is_manually_edited' => (bool) ($primary->is_manually_edited ?? false),
                ];
            }

            $sourceLang = $this->getSourceLanguage();
            if ($sourceLang !== $languageCode) {
                $row = $rows->firstWhere('language_code', $sourceLang);
                if ($row !== null && is_string($row->translated_value) && trim($row->translated_value) !== '') {
                    return [
                        'value' => (string) $row->translated_value,
                        'source' => 'source_lang',
                        'is_manually_edited' => (bool) ($row->is_manually_edited ?? false),
                    ];
                }
            }

            $defaultLang = $this->resolveDefaultLanguageCode();
            if ($defaultLang !== $languageCode && $defaultLang !== $sourceLang) {
                $row = $rows->firstWhere('language_code', $defaultLang);
                if ($row !== null && is_string($row->translated_value) && trim($row->translated_value) !== '') {
                    return [
                        'value' => (string) $row->translated_value,
                        'source' => 'default_lang',
                        'is_manually_edited' => (bool) ($row->is_manually_edited ?? false),
                    ];
                }
            }
        }

        if ($base !== null) {
            return ['value' => $base, 'source' => 'base_table', 'is_manually_edited' => false];
        }

        return ['value' => null, 'source' => 'missing', 'is_manually_edited' => false];
    }

    /**
     * Bulk read every language's value for a field. Used by the admin form
     * to populate the per-flag tabs.
     *
     * @return array<string, array{value: string, is_manually_edited: bool, translation_status: string}>
     *                                                                                                   keyed by language code
     */
    public function getAllTranslationsForField(string $field): array
    {
        if (! $this->relationLoaded('translations')) {
            $this->load('translations');
        }

        $out = [];
        foreach ($this->translations->where('field_name', $field) as $row) {
            $out[(string) $row->language_code] = [
                'value' => (string) $row->translated_value,
                'is_manually_edited' => (bool) ($row->is_manually_edited ?? false),
                'translation_status' => (string) ($row->translation_status ?? 'manual'),
            ];
        }

        return $out;
    }

    /**
     * Hook into the model lifecycle.
     *
     * On create, set source_lang from a request hint (?source_lang= or a
     * setSourceLang() call) before save. Default is the platform default.
     *
     * On save, if any translatable base-column field changed, write that
     * change as a manually-edited row in content_translations for the
     * entity's source_lang and dispatch the AI translator. This keeps old
     * call sites that do `$hotel->update(['hotel_name' => …])` working
     * with the new architecture — the base column write becomes equivalent
     * to "operator just edited the source-language version."
     */
    public static function bootHasTranslations(): void
    {
        static::saved(function ($model): void {
            if (! method_exists($model, 'getTranslatableFields')) {
                return;
            }

            $fields = $model->getTranslatableFields();
            if ($fields === []) {
                return;
            }

            $changed = $model->getChanges();
            $sources = [];
            foreach ($fields as $field) {
                if (! array_key_exists($field, $changed)) {
                    continue;
                }
                $value = $model->getAttribute($field);
                if (is_string($value) && trim($value) !== '') {
                    $sources[$field] = $value;
                }
            }

            if ($sources === []) {
                return;
            }

            $sourceLang = method_exists($model, 'getSourceLanguage')
                ? $model->getSourceLanguage()
                : 'en';

            DB::transaction(function () use ($model, $sources, $sourceLang): void {
                foreach ($sources as $field => $value) {
                    ContentTranslation::query()->updateOrCreate(
                        [
                            'entity_type' => $model->getTranslatableEntityType(),
                            'entity_id' => (int) $model->getKey(),
                            'language_code' => $sourceLang,
                            'field_name' => $field,
                        ],
                        [
                            'translated_value' => $value,
                            'is_manually_edited' => true,
                            'translation_status' => 'manual',
                        ]
                    );
                }
            });

            if (! (bool) env('AI_TRANSLATE_AUTO', true)) {
                return;
            }

            TranslateContentJob::dispatch(
                $model->getTranslatableEntityType(),
                (int) $model->getKey(),
                $sources,
                $sourceLang,
            );
        });
    }

    private function resolveDefaultLanguageCode(): string
    {
        return Cache::remember(
            'localization_default_language_code',
            600,
            function (): string {
                $default = SupportedLanguage::query()->where('is_default', true)->value('code');

                return is_string($default) && $default !== '' ? $default : 'en';
            }
        );
    }
}

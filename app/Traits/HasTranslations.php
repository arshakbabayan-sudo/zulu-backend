<?php

namespace App\Traits;

use App\Jobs\TranslateContentJob;
use App\Models\ContentTranslation;
use App\Models\SupportedLanguage;
use App\Services\Localization\LocalizationService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

trait HasTranslations
{
    public function getTranslatableEntityType(): string
    {
        return strtolower(class_basename($this));
    }

    /**
     * Per-model list of column names that should be auto-translated via
     * the Claude AI translator when the source value changes. Defaults to
     * empty; models opt in by declaring a `$translatableFields` array.
     *
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
     * All content_translations rows for this model instance.
     *
     * Use eager loading on list endpoints to avoid N+1:
     *   Hotel::with('translations')->paginate()
     *
     * Without eager loading, getTranslated() falls back to a per-call
     * query via LocalizationService — correct but slow on large lists.
     *
     * @return HasMany<ContentTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ContentTranslation::class, 'entity_id')
            ->where('entity_type', $this->getTranslatableEntityType());
    }

    /**
     * Return the translated value for a field with an explicit four-step fallback.
     *
     * Resolution order (a value at any step short-circuits the chain):
     *   1. content_translations row for the requested $languageCode
     *   2. content_translations row for the platform default language
     *      (from supported_languages.is_default, cached 10 min)
     *   3. base table column value (e.g. hotels.hotel_name) — the source-of-truth
     *      English text the operator entered when creating the entity
     *   4. caller-supplied $fallback (defaults to null)
     *
     * Note that step 3 is what makes the system robust against missing
     * translations: every entity has SOMETHING to display, in whatever language
     * it was first entered. Use {@see self::getTranslationSource()} when you
     * need to know which step actually produced the value (e.g. to show an
     * "Untranslated" badge in admin).
     */
    public function getTranslated(string $field, string $languageCode, ?string $fallback = null): ?string
    {
        $defaultFallback = $this->attributes[$field] ?? null;
        $defaultFallback = $defaultFallback !== null ? (string) $defaultFallback : null;
        $effectiveFallback = $fallback ?? $defaultFallback;

        if ($this->relationLoaded('translations')) {
            $fieldMatches = $this->translations->where('field_name', $field);

            $primary = $fieldMatches->firstWhere('language_code', $languageCode)?->translated_value;
            if ($primary !== null && $primary !== '') {
                return (string) $primary;
            }

            $defaultCode = $this->resolveDefaultLanguageCode();
            if ($defaultCode !== $languageCode && $defaultCode !== '') {
                $defaultValue = $fieldMatches->firstWhere('language_code', $defaultCode)?->translated_value;
                if ($defaultValue !== null && $defaultValue !== '') {
                    return (string) $defaultValue;
                }
            }

            return $effectiveFallback;
        }

        return app(LocalizationService::class)->getTranslation(
            $this->getTranslatableEntityType(),
            (int) $this->getKey(),
            $field,
            $languageCode,
            $effectiveFallback
        );
    }

    /**
     * Same resolution chain as {@see self::getTranslated()} but reports which
     * step produced the returned value. Useful when admin UI needs to mark
     * a row as "showing the source-language fallback, not a real translation".
     *
     * @return array{value: ?string, source: 'translated'|'default_lang'|'base_table'|'missing'}
     */
    public function getTranslationSource(string $field, string $languageCode): array
    {
        $base = $this->attributes[$field] ?? null;
        $base = $base !== null ? (string) $base : null;

        if ($this->relationLoaded('translations')) {
            $fieldMatches = $this->translations->where('field_name', $field);

            $primary = $fieldMatches->firstWhere('language_code', $languageCode)?->translated_value;
            if ($primary !== null && $primary !== '') {
                return ['value' => (string) $primary, 'source' => 'translated'];
            }

            $defaultCode = $this->resolveDefaultLanguageCode();
            if ($defaultCode !== $languageCode && $defaultCode !== '') {
                $defaultValue = $fieldMatches->firstWhere('language_code', $defaultCode)?->translated_value;
                if ($defaultValue !== null && $defaultValue !== '') {
                    return ['value' => (string) $defaultValue, 'source' => 'default_lang'];
                }
            }
        } else {
            // Without eager loading we cannot cheaply distinguish steps 1 vs 2 —
            // call the service for the value but report the rough source.
            $value = app(LocalizationService::class)->getTranslation(
                $this->getTranslatableEntityType(),
                (int) $this->getKey(),
                $field,
                $languageCode,
                null
            );
            if ($value !== null && $value !== '') {
                return ['value' => $value, 'source' => 'translated'];
            }
        }

        if ($base !== null && $base !== '') {
            return ['value' => $base, 'source' => 'base_table'];
        }

        return ['value' => null, 'source' => 'missing'];
    }

    /**
     * Hook the trait into the model boot lifecycle. After a model saves,
     * if any of its translatable fields changed, dispatch a background
     * job that translates the new value into every other supported
     * locale. Setting AI_TRANSLATE_AUTO=false in .env disables the
     * automatic dispatch (manual translation via admin endpoint still
     * works).
     */
    public static function bootHasTranslations(): void
    {
        static::saved(function ($model): void {
            if (! method_exists($model, 'getTranslatableFields')) {
                return;
            }
            if (! (bool) env('AI_TRANSLATE_AUTO', true)) {
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

            TranslateContentJob::dispatch(
                $model->getTranslatableEntityType(),
                (int) $model->getKey(),
                $sources,
                null,
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

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

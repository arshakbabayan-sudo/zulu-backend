<?php

namespace App\Traits;

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

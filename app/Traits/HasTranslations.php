<?php

namespace App\Traits;

use App\Services\Localization\LocalizationService;

trait HasTranslations
{
    public function getTranslatableEntityType(): string
    {
        return strtolower(class_basename($this));
    }

    public function getTranslated(string $field, string $languageCode, ?string $fallback = null): ?string
    {
        $defaultFallback = $this->attributes[$field] ?? null;
        $defaultFallback = $defaultFallback !== null ? (string) $defaultFallback : null;

        return app(LocalizationService::class)->getTranslation(
            $this->getTranslatableEntityType(),
            (int) $this->getKey(),
            $field,
            $languageCode,
            $fallback ?? $defaultFallback
        );
    }
}

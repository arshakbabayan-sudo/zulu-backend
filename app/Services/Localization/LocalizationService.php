<?php

namespace App\Services\Localization;

use App\Models\ContentTranslation;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\SupportedLanguage;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

class LocalizationService
{
    /**
     * @return Collection<int, SupportedLanguage>
     */
    public function getSupportedLanguages(bool $enabledOnly = true): Collection
    {
        $q = SupportedLanguage::query()->orderBy('sort_order')->orderBy('code');
        if ($enabledOnly) {
            $q->where('is_enabled', true);
        }

        return $q->get();
    }

    public function getDefaultLanguage(): ?SupportedLanguage
    {
        return SupportedLanguage::query()->where('is_default', true)->first();
    }

    public function toggleLanguageEnabled(SupportedLanguage $language): SupportedLanguage
    {
        $language->update(['is_enabled' => ! $language->is_enabled]);

        return $language->fresh();
    }

    public function upsertNotificationTemplate(
        string $eventType,
        string $languageCode,
        string $channel,
        string $titleTemplate,
        string $bodyTemplate,
        ?bool $isActive = null
    ): NotificationTemplate {
        if (! in_array($eventType, Notification::EVENT_TYPES, true)) {
            throw new InvalidArgumentException('Invalid event_type.');
        }
        if (! in_array($channel, NotificationTemplate::CHANNELS, true)) {
            throw new InvalidArgumentException('Invalid channel.');
        }

        $langExists = SupportedLanguage::query()->where('code', $languageCode)->exists();
        if (! $langExists) {
            throw new InvalidArgumentException('Invalid language_code.');
        }

        $existing = NotificationTemplate::query()
            ->where('event_type', $eventType)
            ->where('language_code', $languageCode)
            ->where('channel', $channel)
            ->first();

        $active = $isActive ?? $existing?->is_active ?? true;

        return NotificationTemplate::query()->updateOrCreate(
            [
                'event_type' => $eventType,
                'language_code' => $languageCode,
                'channel' => $channel,
            ],
            [
                'title_template' => $titleTemplate,
                'body_template' => $bodyTemplate,
                'is_active' => $active,
            ]
        );
    }

    public function resolveLanguage(string $requested): string
    {
        $requested = trim($requested);
        if ($requested === '') {
            return $this->getDefaultLanguage()?->code ?? 'en';
        }

        try {
            $codes = SupportedLanguage::query()
                ->where('is_enabled', true)
                ->pluck('code')
                ->all();

            /** @var array<string, string> $lowerToCanonical */
            $lowerToCanonical = [];
            foreach ($codes as $code) {
                $c = (string) $code;
                $lowerToCanonical[strtolower($c)] = $c;
            }

            $lower = strtolower($requested);
            if (isset($lowerToCanonical[$lower])) {
                return $lowerToCanonical[$lower];
            }

            $prefix = strtolower(substr($requested, 0, 2));
            if (strlen($prefix) === 2 && isset($lowerToCanonical[$prefix])) {
                return $lowerToCanonical[$prefix];
            }

            return $this->getDefaultLanguage()?->code ?? 'en';
        } catch (Throwable) {
            return 'en';
        }
    }

    public function getTranslation(
        string $entityType,
        int $entityId,
        string $fieldName,
        string $languageCode,
        ?string $fallback = null
    ): ?string {
        try {
            $defaultCode = $this->getDefaultLanguage()?->code ?? 'en';

            $row = ContentTranslation::query()
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('field_name', $fieldName)
                ->where('language_code', $languageCode)
                ->value('translated_value');

            if ($row !== null && $row !== '') {
                return (string) $row;
            }

            if ($languageCode !== $defaultCode) {
                $rowDefault = ContentTranslation::query()
                    ->where('entity_type', $entityType)
                    ->where('entity_id', $entityId)
                    ->where('field_name', $fieldName)
                    ->where('language_code', $defaultCode)
                    ->value('translated_value');

                if ($rowDefault !== null && $rowDefault !== '') {
                    return (string) $rowDefault;
                }
            }

            return $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string|null>
     */
    public function getTranslations(
        string $entityType,
        int $entityId,
        string $languageCode,
        array $fields = []
    ): array {
        try {
            if ($fields !== []) {
                $out = [];
                foreach ($fields as $field) {
                    $out[$field] = $this->getTranslation($entityType, $entityId, $field, $languageCode, null);
                }

                return $out;
            }

            $fieldNames = ContentTranslation::query()
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->distinct()
                ->pluck('field_name')
                ->all();

            $out = [];
            foreach ($fieldNames as $fieldName) {
                $out[(string) $fieldName] = $this->getTranslation(
                    $entityType,
                    $entityId,
                    (string) $fieldName,
                    $languageCode,
                    null
                );
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    public function setTranslation(
        string $entityType,
        int $entityId,
        string $languageCode,
        string $fieldName,
        string $value
    ): ContentTranslation {
        if (! in_array($entityType, ContentTranslation::ENTITY_TYPES, true)) {
            throw new InvalidArgumentException('Invalid entity_type.');
        }
        if (! in_array($fieldName, ContentTranslation::TRANSLATABLE_FIELDS, true)) {
            throw new InvalidArgumentException('Invalid field_name.');
        }

        $exists = SupportedLanguage::query()
            ->where('code', $languageCode)
            ->where('is_enabled', true)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException('Invalid or disabled language_code.');
        }

        return ContentTranslation::query()->updateOrCreate(
            [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'language_code' => $languageCode,
                'field_name' => $fieldName,
            ],
            ['translated_value' => $value]
        );
    }

    /**
     * @param  array<string, string>  $fieldValues
     * @return list<ContentTranslation>
     */
    public function setTranslations(
        string $entityType,
        int $entityId,
        string $languageCode,
        array $fieldValues
    ): array {
        $saved = [];
        foreach ($fieldValues as $fieldName => $value) {
            $saved[] = $this->setTranslation(
                $entityType,
                $entityId,
                $languageCode,
                (string) $fieldName,
                (string) $value
            );
        }

        return $saved;
    }

    public function deleteTranslations(string $entityType, int $entityId, ?string $languageCode = null): int
    {
        $q = ContentTranslation::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId);

        if ($languageCode !== null && $languageCode !== '') {
            $q->where('language_code', $languageCode);
        }

        return (int) $q->delete();
    }

    public function getNotificationTemplate(
        string $eventType,
        string $languageCode,
        string $channel = 'in_app'
    ): ?NotificationTemplate {
        $defaultCode = $this->getDefaultLanguage()?->code ?? 'en';

        $find = function (string $lang) use ($eventType, $channel): ?NotificationTemplate {
            return NotificationTemplate::query()
                ->where('event_type', $eventType)
                ->where('language_code', $lang)
                ->where('channel', $channel)
                ->where('is_active', true)
                ->first();
        };

        $template = $find($languageCode);
        if ($template !== null) {
            return $template;
        }

        if ($languageCode !== $defaultCode) {
            return $find($defaultCode);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{title: string, body: string}
     */
    public function renderTemplate(NotificationTemplate $template, array $variables = []): array
    {
        $title = (string) $template->title_template;
        $body = (string) $template->body_template;

        foreach ($variables as $key => $value) {
            $placeholder = '{{'.$key.'}}';
            $replacement = $value === null ? '' : (string) $value;
            $title = str_replace($placeholder, $replacement, $title);
            $body = str_replace($placeholder, $replacement, $body);
        }

        return [
            'title' => $title,
            'body' => $body,
        ];
    }
}

<?php

namespace App\Services\Localization;

use App\Models\ContentTranslation;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\SupportedLanguage;
use App\Models\UiTranslation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /**
     * @return Collection<int, SupportedLanguage>
     */
    public function getAllLanguages(): Collection
    {
        return SupportedLanguage::query()->orderBy('sort_order')->orderBy('code')->get();
    }

    public function setDefaultLanguage(SupportedLanguage $language): SupportedLanguage
    {
        SupportedLanguage::query()->update(['is_default' => false]);
        $language->update(['is_default' => true, 'is_enabled' => true]);

        return $language->fresh();
    }

    public function updateLanguage(SupportedLanguage $language, string $name, string $nameEn, bool $rtl): SupportedLanguage
    {
        $language->update([
            'name'    => trim($name),
            'name_en' => trim($nameEn),
            'rtl'     => $rtl,
        ]);

        return $language->fresh();
    }

    public function toggleLanguageEnabled(SupportedLanguage $language): SupportedLanguage
    {
        $language->update(['is_enabled' => ! $language->is_enabled]);

        return $language->fresh();
    }

    public function createLanguage(string $code, string $name, string $nameEn, bool $rtl = false): SupportedLanguage
    {
        $code = strtolower(trim($code));

        $exists = SupportedLanguage::query()->where('code', $code)->exists();
        if ($exists) {
            throw new InvalidArgumentException('A language with this code already exists.');
        }

        $maxSort = (int) SupportedLanguage::query()->max('sort_order');

        return SupportedLanguage::query()->create([
            'code'       => $code,
            'name'       => trim($name),
            'name_en'    => trim($nameEn),
            'is_default' => false,
            'is_enabled' => true,
            'sort_order' => $maxSort + 1,
        ]);
    }

    public function deleteLanguage(SupportedLanguage $language): void
    {
        if ($language->is_default) {
            throw new InvalidArgumentException('Cannot delete the default language.');
        }

        $language->delete();
    }

    /**
     * @return array<string, string>
     */
    public function getUiTranslations(string $languageCode): array
    {
        $cacheKey = 'ui_translations_' . $languageCode;

        return Cache::rememberForever($cacheKey, function () use ($languageCode): array {
            return UiTranslation::query()
                ->where('language_code', $languageCode)
                ->pluck('value', 'key')
                ->all();
        });
    }

    /**
     * @param  array<string, string>  $keyValues
     */
    public function setUiTranslations(string $languageCode, array $keyValues): int
    {
        $langExists = SupportedLanguage::query()->where('code', $languageCode)->exists();
        if (! $langExists) {
            throw new InvalidArgumentException('Invalid or unsupported language_code.');
        }

        $count = 0;
        foreach ($keyValues as $key => $value) {
            UiTranslation::query()->updateOrCreate(
                ['language_code' => $languageCode, 'key' => (string) $key],
                ['value' => (string) $value]
            );
            $count++;
        }

        Cache::forget('ui_translations_' . $languageCode);

        return $count;
    }

    /**
     * @param  list<string>  $keys
     */
    public function deleteUiTranslations(string $languageCode, array $keys = []): int
    {
        $q = UiTranslation::query()->where('language_code', $languageCode);

        if ($keys !== []) {
            $q->whereIn('key', $keys);
        }

        $deleted = (int) $q->delete();
        Cache::forget('ui_translations_' . $languageCode);

        return $deleted;
    }

    /**
     * @return array<string, string>  Paginated key-value rows for admin editor
     */
    public function getUiTranslationsPaginated(string $languageCode, int $page, int $perPage, string $search = ''): array
    {
        $defaultCode = $this->getDefaultLanguage()?->code ?? 'en';

        // Non-default languages: full canonical key list from default language, overlay selected values.
        if ($languageCode !== $defaultCode) {
            $q = DB::table('ui_translations as d')
                ->leftJoin('ui_translations as s', function ($join) use ($languageCode): void {
                    $join->on('d.key', '=', 's.key')
                        ->where('s.language_code', '=', $languageCode);
                })
                ->where('d.language_code', $defaultCode);

            if ($search !== '') {
                $like = '%' . $search . '%';
                $q->where(function ($sub) use ($like): void {
                    $sub->where('d.key', 'like', $like)
                        ->orWhere('d.value', 'like', $like)
                        ->orWhere('s.value', 'like', $like);
                });
            }

            $total = (int) (clone $q)->count();
            $rows = (clone $q)
                ->select([
                    'd.key',
                    DB::raw("COALESCE(s.value, '') as value"),
                ])
                ->orderBy('d.key')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get()
                ->map(fn ($r) => ['key' => $r->key, 'value' => (string) $r->value])
                ->values()
                ->all();

            return [
                'data'         => $rows,
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / $perPage),
            ];
        }

        $q = UiTranslation::query()->where('language_code', $languageCode);

        if ($search !== '') {
            $q->where(function ($sub) use ($search): void {
                $sub->where('key', 'like', '%' . $search . '%')
                    ->orWhere('value', 'like', '%' . $search . '%');
            });
        }

        $total = (int) $q->count();
        $rows = $q->orderBy('key')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get(['key', 'value'])
            ->map(fn ($r) => ['key' => $r->key, 'value' => $r->value])
            ->values()
            ->all();

        return [
            'data'         => $rows,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int) ceil($total / $perPage),
        ];
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

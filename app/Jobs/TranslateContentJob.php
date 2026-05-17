<?php

namespace App\Jobs;

use App\Models\ContentTranslation;
use App\Services\Localization\LocalizationService;
use App\Services\Localization\TranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queue job: translate a content-bearing model's source-language fields
 * into every other enabled language via Claude, then persist via the
 * LocalizationService into content_translations.
 *
 * Dispatched from the HasTranslations trait `saved` hook and from the
 * admin "re-translate" endpoint. Rows already flagged
 * `is_manually_edited=true` are skipped — those represent operator-edited
 * translations that must never be overwritten by AI. Re-running this job
 * with `$forceOverwrite=true` ignores the manual lock (the admin "AI
 * re-translate this row" button).
 */
class TranslateContentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 90;

    /**
     * @param  array<string, string>  $sourceValues  field_name => source value
     * @param  list<string>|null  $onlyLocales  if set, translate only into these locales
     */
    public function __construct(
        public string $entityType,
        public int $entityId,
        public array $sourceValues,
        public ?string $sourceLocale = null,
        public bool $forceOverwrite = false,
        public ?array $onlyLocales = null,
    ) {}

    public function handle(TranslationService $translator, LocalizationService $localization): void
    {
        if (! in_array($this->entityType, ContentTranslation::ENTITY_TYPES, true)) {
            Log::warning('TranslateContentJob: unknown entity_type', [
                'entity_type' => $this->entityType,
            ]);

            return;
        }

        $fieldsToTranslate = [];
        foreach ($this->sourceValues as $field => $value) {
            if (! in_array($field, ContentTranslation::TRANSLATABLE_FIELDS, true)) {
                continue;
            }
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $fieldsToTranslate[$field] = $value;
        }

        if ($fieldsToTranslate === []) {
            return;
        }

        $translated = $translator->translateMany(
            $fieldsToTranslate,
            $this->sourceLocale,
            $this->onlyLocales
        );

        if ($translated === []) {
            Log::info('TranslateContentJob: translator returned no values, nothing to persist', [
                'entity_type' => $this->entityType,
                'entity_id' => $this->entityId,
                'fields' => array_keys($fieldsToTranslate),
            ]);

            return;
        }

        DB::transaction(function () use ($translated, $localization): void {
            foreach ($translated as $field => $localeMap) {
                foreach ($localeMap as $locale => $value) {
                    try {
                        // AI-produced writes: never overwrite an operator's
                        // manual edit unless the caller passed forceOverwrite
                        // (admin "Re-translate" button).
                        $localization->setTranslation(
                            $this->entityType,
                            $this->entityId,
                            $locale,
                            $field,
                            $value,
                            isManualEdit: false,
                            translationStatus: 'ai_completed',
                            respectManualLock: ! $this->forceOverwrite,
                        );
                    } catch (Throwable $e) {
                        Log::warning('TranslateContentJob: setTranslation failed for one row', [
                            'entity_type' => $this->entityType,
                            'entity_id' => $this->entityId,
                            'locale' => $locale,
                            'field' => $field,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        });

        Log::info('TranslateContentJob: persisted translations', [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'fields' => array_keys($translated),
        ]);
    }
}

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
 * into every other supported language via Claude, then persist via the
 * LocalizationService into content_translations.
 *
 * Dispatched from HasTranslations trait on `saved` when any whitelisted
 * field changed. Idempotent — re-running for the same entity overwrites
 * any prior auto-translation (manual overrides should set a flag in a
 * future iteration; for now, a manual edit via the admin endpoint just
 * sets the translation again).
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
     */
    public function __construct(
        public string $entityType,
        public int $entityId,
        public array $sourceValues,
        public ?string $sourceLocale = null,
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

        $translated = $translator->translateMany($fieldsToTranslate, $this->sourceLocale);

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
                        $localization->setTranslation(
                            $this->entityType,
                            $this->entityId,
                            $locale,
                            $field,
                            $value
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

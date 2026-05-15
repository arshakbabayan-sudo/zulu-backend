<?php

namespace App\Jobs;

use App\Models\UiTranslation;
use App\Services\Localization\TranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Queue job: fill missing ui_translations rows for a single key by
 * translating its source-language value via Claude. Dispatched by
 * TranslationsScanCommand when it finds a key that exists in the
 * source language but is missing one or more target locales.
 *
 * Idempotent — re-running for the same (key, source, targets) just
 * overwrites with a fresh translation. Existing non-empty values for
 * a target locale are skipped (we never clobber human edits without
 * an explicit override flag).
 */
class TranslateUiStringJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    /**
     * @param  list<string>  $targetLocales
     */
    public function __construct(
        public string $key,
        public string $sourceValue,
        public string $sourceLocale,
        public array $targetLocales,
        public bool $overwriteExisting = false,
    ) {}

    public function handle(TranslationService $translator): void
    {
        $source = trim($this->sourceValue);
        if ($source === '') {
            return;
        }

        // Determine which target locales actually need filling.
        $needs = [];
        foreach ($this->targetLocales as $locale) {
            $existing = UiTranslation::query()
                ->where('language_code', $locale)
                ->where('key', $this->key)
                ->value('value');

            if ($this->overwriteExisting || $existing === null || trim((string) $existing) === '') {
                $needs[] = $locale;
            }
        }

        if ($needs === []) {
            return;
        }

        $translations = $translator->translate($source, $this->sourceLocale, $needs);
        if ($translations === []) {
            Log::info('TranslateUiStringJob: translator returned nothing', [
                'key' => $this->key,
                'targets' => $needs,
            ]);

            return;
        }

        DB::transaction(function () use ($translations): void {
            foreach ($translations as $locale => $value) {
                UiTranslation::query()->updateOrCreate(
                    ['language_code' => $locale, 'key' => $this->key],
                    ['value' => $value]
                );
                Cache::forget('ui_translations_'.$locale);
            }
        });

        Log::info('TranslateUiStringJob: filled gaps', [
            'key' => $this->key,
            'filled' => array_keys($translations),
        ]);
    }
}

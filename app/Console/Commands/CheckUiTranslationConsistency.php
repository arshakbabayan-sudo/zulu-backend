<?php

namespace App\Console\Commands;

use App\Models\UiTranslation;
use App\Services\Localization\LocalizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class CheckUiTranslationConsistency extends Command
{
    protected $signature = 'localization:check-ui-consistency
        {--path= : Path to canonical defaults JSON (defaults to database/data/ui_translation_defaults_en.json)}
        {--lang= : Language code to check (defaults to configured default language)}
        {--allow-extra : Do not fail when DB has keys outside canonical JSON}';

    protected $description = 'Guardrail check for canonical UI translation key consistency between JSON defaults and DB rows.';

    public function handle(LocalizationService $localization): int
    {
        $pathOption = (string) ($this->option('path') ?? '');
        $path = $pathOption !== '' ? $pathOption : database_path('data/ui_translation_defaults_en.json');
        if (! File::isFile($path)) {
            $this->error('Missing defaults JSON file: '.$path);

            return self::FAILURE;
        }

        $raw = File::get($path);
        /** @var array<string, string>|null $defaults */
        $defaults = json_decode($raw, true);
        if (! is_array($defaults)) {
            $this->error('Invalid JSON in defaults file: '.$path);

            return self::FAILURE;
        }

        $langOption = trim((string) ($this->option('lang') ?? ''));
        if ($langOption !== '') {
            $languageCode = strtolower($langOption);
        } else {
            $defaultLanguage = $localization->getDefaultLanguage();
            if ($defaultLanguage === null) {
                throw new InvalidArgumentException('No default language configured in supported_languages.');
            }
            $languageCode = $defaultLanguage->code;
        }

        $canonicalKeys = array_keys($defaults);
        sort($canonicalKeys);

        $dbKeys = UiTranslation::query()
            ->where('language_code', $languageCode)
            ->pluck('key')
            ->map(fn ($key) => (string) $key)
            ->all();
        sort($dbKeys);

        $missingInDb = array_values(array_diff($canonicalKeys, $dbKeys));
        $extraInDb = array_values(array_diff($dbKeys, $canonicalKeys));

        $this->line(sprintf(
            'Language: %s | Canonical keys: %d | DB keys: %d',
            $languageCode,
            count($canonicalKeys),
            count($dbKeys)
        ));
        $this->line(sprintf('Missing in DB: %d', count($missingInDb)));
        $this->line(sprintf('Extra in DB: %d', count($extraInDb)));

        $sampleLimit = 20;
        if ($missingInDb !== []) {
            $this->warn('Sample missing keys: '.implode(', ', array_slice($missingInDb, 0, $sampleLimit)));
        }
        if ($extraInDb !== []) {
            $this->warn('Sample extra keys: '.implode(', ', array_slice($extraInDb, 0, $sampleLimit)));
        }

        $allowExtra = (bool) $this->option('allow-extra');
        if ($missingInDb !== []) {
            $this->error('Consistency check failed: canonical keys are missing in DB.');

            return self::FAILURE;
        }
        if (! $allowExtra && $extraInDb !== []) {
            $this->error('Consistency check failed: DB contains keys outside canonical defaults.');

            return self::FAILURE;
        }

        $this->info('Consistency check passed.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\UiTranslation;
use App\Services\Localization\LocalizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportUiTranslationsCsv extends Command
{
    protected $signature = 'localization:import-ui-csv
        {path : Path to CSV file (columns: key, en, ru, hy)}
        {--lang= : Language code to import (e.g. ru, hy). Use with --force to update default language.}
        {--force : When --lang is the default language, allow overwriting English values}';

    protected $description = 'Import translated UI strings from CSV into ui_translations for one language (skips blank cells; does not touch English without --force)';

    public function handle(LocalizationService $localization): int
    {
        $path = (string) $this->argument('path');
        if (! File::isFile($path)) {
            $this->error('File not found: '.$path);

            return self::FAILURE;
        }

        $lang = strtolower(trim((string) $this->option('lang')));
        if ($lang === '') {
            $this->error('Missing --lang (e.g. --lang=ru or --lang=hy).');

            return self::FAILURE;
        }

        $defaultCode = $localization->getDefaultLanguage()?->code ?? 'en';
        $force = (bool) $this->option('force');

        if ($lang === strtolower($defaultCode) && ! $force) {
            $this->error(
                'Refusing to modify default language ('.$defaultCode.') without --force. '.
                'Use --force only when intentionally updating English from CSV.'
            );

            return self::FAILURE;
        }

        try {
            $canonicalKeys = UiTranslation::query()
                ->where('language_code', $defaultCode)
                ->pluck('key')
                ->flip()
                ->all();
        } catch (\Throwable $e) {
            $this->error('Cannot load canonical keys: '.$e->getMessage());

            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error('Cannot read file: '.$path);

            return self::FAILURE;
        }

        $firstLine = (string) fgets($handle);
        if (str_starts_with($firstLine, "\xEF\xBB\xBF")) {
            $firstLine = substr($firstLine, 3);
        }
        $header = str_getcsv($firstLine);
        $headerLower = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);

        $idxKey = array_search('key', $headerLower, true);
        $idxLang = array_search($lang, $headerLower, true);

        if ($idxKey === false) {
            fclose($handle);
            $this->error('CSV must include a "key" column.');

            return self::FAILURE;
        }

        if ($idxLang === false) {
            fclose($handle);
            $this->error('CSV must include a column named "'.$lang.'" matching --lang.');

            return self::FAILURE;
        }

        /** @var array<string, string> $toSave */
        $toSave = [];
        $skippedBlank = 0;
        $skippedUnknownKey = 0;
        $lineNo = 1;

        while (($line = fgets($handle)) !== false) {
            $lineNo++;
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $key = isset($cols[$idxKey]) ? trim((string) $cols[$idxKey]) : '';
            if ($key === '') {
                continue;
            }
            $value = isset($cols[$idxLang]) ? trim((string) $cols[$idxLang]) : '';
            if ($value === '') {
                $skippedBlank++;

                continue;
            }
            if (! isset($canonicalKeys[$key])) {
                $skippedUnknownKey++;
                if ($this->output->isVerbose()) {
                    $this->warn("Line {$lineNo}: unknown key (not in default language): {$key}");
                }

                continue;
            }
            $toSave[$key] = $value;
        }

        fclose($handle);

        if ($toSave === []) {
            $this->warn('Nothing to import (no non-blank values for known keys).');
            $this->line("Skipped blank: {$skippedBlank}, skipped unknown key: {$skippedUnknownKey}");

            return self::SUCCESS;
        }

        try {
            $saved = $localization->setUiTranslations($lang, $toSave);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Imported %d UI string(s) for language "%s". Skipped blank: %d. Skipped unknown key: %d.',
            $saved,
            $lang,
            $skippedBlank,
            $skippedUnknownKey
        ));

        return self::SUCCESS;
    }
}

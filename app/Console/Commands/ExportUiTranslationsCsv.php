<?php

namespace App\Console\Commands;

use App\Models\UiTranslation;
use App\Services\Localization\LocalizationService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ExportUiTranslationsCsv extends Command
{
    protected $signature = 'localization:export-ui-csv
        {--output= : Output CSV path (default: storage/app/ui_translations_bulk.csv)}';

    protected $description = 'Export canonical UI keys with English values and current ru/hy columns (CSV: key,en,ru,hy)';

    public function handle(LocalizationService $localization): int
    {
        $defaultLang = $localization->getDefaultLanguage();
        if ($defaultLang === null) {
            throw new InvalidArgumentException('No default language configured in supported_languages.');
        }

        $defaultCode = $defaultLang->code;

        $enRows = UiTranslation::query()
            ->where('language_code', $defaultCode)
            ->orderBy('key')
            ->get(['key', 'value']);

        /** @var array<string, string> $ruByKey */
        $ruByKey = UiTranslation::query()
            ->where('language_code', 'ru')
            ->pluck('value', 'key')
            ->all();

        /** @var array<string, string> $hyByKey */
        $hyByKey = UiTranslation::query()
            ->where('language_code', 'hy')
            ->pluck('value', 'key')
            ->all();

        $outPath = (string) ($this->option('output') ?: storage_path('app/ui_translations_bulk.csv'));
        $dir = dirname($outPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fh = fopen($outPath, 'wb');
        if ($fh === false) {
            $this->error('Cannot open output file: '.$outPath);

            return self::FAILURE;
        }

        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['key', 'en', 'ru', 'hy']);

        foreach ($enRows as $row) {
            $key = (string) $row->key;
            fputcsv($fh, [
                $key,
                (string) $row->value,
                (string) ($ruByKey[$key] ?? ''),
                (string) ($hyByKey[$key] ?? ''),
            ]);
        }

        fclose($fh);

        $this->info(sprintf(
            'Wrote %d rows to %s (default language: %s)',
            $enRows->count(),
            $outPath,
            $defaultCode
        ));

        return self::SUCCESS;
    }
}

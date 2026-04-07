<?php

namespace App\Console\Commands;

use App\Models\UiTranslation;
use App\Services\Localization\LocalizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class SyncUiTranslationDefaults extends Command
{
    protected $signature = 'localization:sync-ui-defaults
        {--force : Re-write values from the JSON file for keys that already exist (default language only)}';

    protected $description = 'Ensure all canonical UI translation keys exist for the default language (English defaults from frontend inventory)';

    public function handle(LocalizationService $localization): int
    {
        $path = database_path('data/ui_translation_defaults_en.json');
        if (! File::isFile($path)) {
            $this->error('Missing file: '.$path);

            return self::FAILURE;
        }

        $raw = File::get($path);
        /** @var array<string, string>|null $defaults */
        $defaults = json_decode($raw, true);
        if (! is_array($defaults)) {
            $this->error('Invalid JSON in '.$path);

            return self::FAILURE;
        }

        $defaultLang = $localization->getDefaultLanguage();
        if ($defaultLang === null) {
            throw new InvalidArgumentException('No default language configured in supported_languages.');
        }

        $code = $defaultLang->code;
        $force = (bool) $this->option('force');

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($defaults as $key => $value) {
            $key = (string) $key;
            $value = (string) $value;

            $existing = UiTranslation::query()
                ->where('language_code', $code)
                ->where('key', $key)
                ->first();

            if ($existing === null) {
                UiTranslation::query()->create([
                    'language_code' => $code,
                    'key' => $key,
                    'value' => $value,
                ]);
                $inserted++;
            } elseif ($force) {
                $existing->update(['value' => $value]);
                $updated++;
            } else {
                $skipped++;
            }
        }

        Cache::forget('ui_translations_'.$code);

        $this->info(sprintf(
            'Default language: %s | Total canonical keys: %d | Inserted: %d | Skipped (already present): %d',
            $code,
            count($defaults),
            $inserted,
            $skipped
        ));
        if ($force && $updated > 0) {
            $this->info("Updated existing rows (--force): {$updated}");
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\SupportedLanguage;
use App\Models\UiTranslation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Seed UI translations for a single non-default language from
 * `database/data/ui_translation_defaults_{lang}.json`.
 *
 * Usage:
 *   php artisan localization:seed-ui-language hy
 *   php artisan localization:seed-ui-language ru --force
 *
 * The English source file is handled by the existing
 * `localization:sync-ui-defaults` command. This command is intentionally
 * narrower: it only inserts/updates translations for the requested
 * non-default language, using the English defaults JSON as the
 * authoritative key set when --fill-missing is used.
 */
class SeedUiTranslationsForLanguage extends Command
{
    protected $signature = 'localization:seed-ui-language
        {lang : Language code (e.g. hy, ru)}
        {--force : Overwrite existing rows for this language}
        {--fill-missing : For keys missing in the language JSON, copy the English default value as a fallback so the row exists}';

    protected $description = 'Seed UI translations for a single language from database/data/ui_translation_defaults_{lang}.json';

    public function handle(): int
    {
        $lang = (string) $this->argument('lang');
        if ($lang === '' || strlen($lang) > 8) {
            $this->error('Invalid language code.');

            return self::FAILURE;
        }

        $supported = SupportedLanguage::query()->where('code', $lang)->first();
        if ($supported === null) {
            $this->error("Language '{$lang}' is not in supported_languages.");

            return self::FAILURE;
        }

        $path = database_path("data/ui_translation_defaults_{$lang}.json");
        if (! File::isFile($path)) {
            $this->error('Missing file: '.$path);

            return self::FAILURE;
        }

        $raw = File::get($path);
        /** @var array<string, string>|null $values */
        $values = json_decode($raw, true);
        if (! is_array($values)) {
            $this->error('Invalid JSON in '.$path);

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $fillMissing = (bool) $this->option('fill-missing');

        // Optionally fill missing keys from English defaults so every key has a row.
        if ($fillMissing) {
            $enPath = database_path('data/ui_translation_defaults_en.json');
            if (File::isFile($enPath)) {
                $en = json_decode(File::get($enPath), true);
                if (is_array($en)) {
                    foreach ($en as $k => $v) {
                        if (! array_key_exists($k, $values)) {
                            $values[$k] = (string) $v;
                        }
                    }
                }
            }
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($values as $key => $value) {
            $key = (string) $key;
            $value = (string) $value;

            $existing = UiTranslation::query()
                ->where('language_code', $lang)
                ->where('key', $key)
                ->first();

            if ($existing === null) {
                UiTranslation::query()->create([
                    'language_code' => $lang,
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

        Cache::forget('ui_translations_'.$lang);

        $this->info(sprintf(
            'Language: %s | Total keys: %d | Inserted: %d | Updated (--force): %d | Skipped (already present): %d',
            $lang,
            count($values),
            $inserted,
            $updated,
            $skipped
        ));

        return self::SUCCESS;
    }
}

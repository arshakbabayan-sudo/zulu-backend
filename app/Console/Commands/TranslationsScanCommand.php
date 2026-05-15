<?php

namespace App\Console\Commands;

use App\Jobs\TranslateContentJob;
use App\Jobs\TranslateUiStringJob;
use App\Models\Company;
use App\Models\ContentTranslation;
use App\Models\Excursion;
use App\Models\Hotel;
use App\Models\Offer;
use App\Models\Package;
use App\Models\SupportedLanguage;
use App\Models\Transfer;
use App\Models\UiTranslation;
use App\Models\Visa;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Walk the localisation tables and queue an AI translation job for
 * every gap: each ui_translations key that has a source-language
 * value but is missing one or more target locales, and each
 * translatable model row whose source column has a value but whose
 * content_translations rows are missing.
 *
 * Designed to be triggered manually (from the admin "Languages"
 * page) — not from a scheduled cron. The AI stays asleep until
 * someone explicitly asks it to look around.
 *
 * Usage:
 *   php artisan translations:scan                      # both UI + content
 *   php artisan translations:scan --ui                 # only ui_translations
 *   php artisan translations:scan --content            # only model content
 *   php artisan translations:scan --dry-run            # report counts, queue nothing
 *   php artisan translations:scan --source=en          # use a non-default source locale
 *   php artisan translations:scan --overwrite          # re-translate even when target value already exists
 */
class TranslationsScanCommand extends Command
{
    protected $signature = 'translations:scan
        {--ui : Scan ui_translations only}
        {--content : Scan content_translations only}
        {--dry-run : Count gaps but do not dispatch any jobs}
        {--source= : Source language code (defaults to the configured default language)}
        {--overwrite : Re-translate target locales even when a non-empty value already exists}
        {--limit=0 : Cap the number of jobs dispatched per scope (0 = no cap)}';

    protected $description = 'AI translator wake-up: find missing UI + content translations and queue jobs to fill them.';

    /**
     * Map of model class => translation entity_type label used in
     * content_translations.entity_type. Limited to types that have a
     * working $translatableFields list (declared by HasTranslations
     * users in Step 13.5).
     *
     * @var array<class-string<Model>, string>
     */
    private const TRANSLATABLE_MODELS = [
        Offer::class => 'offer',
        Hotel::class => 'hotel',
        Excursion::class => 'excursion',
        Package::class => 'package',
        Transfer::class => 'transfer',
        Visa::class => 'visa',
        Company::class => 'company',
    ];

    public function handle(): int
    {
        $onlyUi = (bool) $this->option('ui');
        $onlyContent = (bool) $this->option('content');
        $dryRun = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');
        $limit = max(0, (int) $this->option('limit'));

        $source = strtolower(trim((string) ($this->option('source') ?? '')));
        if ($source === '') {
            $source = (string) (SupportedLanguage::query()->where('is_default', true)->value('code') ?? 'en');
        }

        $enabledLocales = SupportedLanguage::query()
            ->where('is_enabled', true)
            ->pluck('code')
            ->map(fn ($c) => strtolower((string) $c))
            ->all();
        $targets = array_values(array_filter($enabledLocales, fn ($l) => $l !== $source));

        if ($targets === []) {
            $this->warn('No target locales enabled (only source language is enabled). Nothing to scan.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Source locale: %s | Target locales: %s | Mode: %s%s',
            $source,
            implode(', ', $targets),
            $dryRun ? 'dry-run' : 'dispatch',
            $overwrite ? ' (overwrite existing)' : ''
        ));
        $this->newLine();

        $totalQueued = 0;

        $scanUi = $onlyUi || ! $onlyContent;
        $scanContent = $onlyContent || ! $onlyUi;

        if ($scanUi) {
            $totalQueued += $this->scanUiTranslations($source, $targets, $dryRun, $overwrite, $limit);
        }
        if ($scanContent) {
            $totalQueued += $this->scanContentTranslations($source, $targets, $dryRun, $overwrite, $limit);
        }

        $this->newLine();
        $this->info(sprintf(
            '%s total: %d %s',
            $dryRun ? 'Would queue' : 'Queued',
            $totalQueued,
            $dryRun ? 'jobs' : 'jobs (Laravel queue worker will process them)'
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $targets
     */
    private function scanUiTranslations(string $source, array $targets, bool $dryRun, bool $overwrite, int $limit): int
    {
        $this->info('--- UI translations ---');

        $sourceRows = UiTranslation::query()
            ->where('language_code', $source)
            ->select(['key', 'value'])
            ->orderBy('key')
            ->get();

        $sourceCount = $sourceRows->count();
        $this->line(sprintf('Source-language rows: %d', $sourceCount));

        if ($sourceCount === 0) {
            $this->warn('No source-language rows found — nothing to do.');

            return 0;
        }

        // Pull every existing target row in one pass, indexed by [locale][key]
        $existing = [];
        UiTranslation::query()
            ->whereIn('language_code', $targets)
            ->select(['id', 'language_code', 'key', 'value'])
            ->orderBy('id')
            ->chunkById(2000, function ($chunk) use (&$existing): void {
                foreach ($chunk as $row) {
                    $existing[$row->language_code][$row->key] = (string) $row->value;
                }
            });

        $gapCount = 0;
        $dispatched = 0;

        foreach ($sourceRows as $row) {
            $key = (string) $row->key;
            $sourceValue = trim((string) $row->value);
            if ($sourceValue === '') {
                continue;
            }

            $missing = [];
            foreach ($targets as $locale) {
                $current = $existing[$locale][$key] ?? '';
                if ($overwrite || trim($current) === '') {
                    $missing[] = $locale;
                }
            }

            if ($missing === []) {
                continue;
            }

            $gapCount++;

            if ($dryRun) {
                continue;
            }

            if ($limit > 0 && $dispatched >= $limit) {
                continue;
            }

            TranslateUiStringJob::dispatch($key, $sourceValue, $source, $missing, $overwrite);
            $dispatched++;
        }

        $this->line(sprintf('Keys with at least one missing target locale: %d', $gapCount));
        $this->line(sprintf('Jobs %s: %d', $dryRun ? 'that would be dispatched' : 'dispatched', $dryRun ? $gapCount : $dispatched));

        return $dryRun ? $gapCount : $dispatched;
    }

    /**
     * @param  list<string>  $targets
     */
    private function scanContentTranslations(string $source, array $targets, bool $dryRun, bool $overwrite, int $limit): int
    {
        $this->info('--- Content translations ---');

        $totalDispatched = 0;

        foreach (self::TRANSLATABLE_MODELS as $modelClass => $entityType) {
            if (! class_exists($modelClass)) {
                continue;
            }

            $instance = new $modelClass;
            if (! method_exists($instance, 'getTranslatableFields')) {
                continue;
            }
            $fields = $instance->getTranslatableFields();
            if ($fields === []) {
                continue;
            }

            $table = $instance->getTable();
            $primaryKey = $instance->getKeyName();

            // Pre-load all existing translations for this entity_type so
            // the per-row check stays in memory.
            $existing = [];
            ContentTranslation::query()
                ->where('entity_type', $entityType)
                ->whereIn('language_code', $targets)
                ->select(['id', 'entity_id', 'language_code', 'field_name', 'translated_value'])
                ->orderBy('id')
                ->chunkById(2000, function ($chunk) use (&$existing): void {
                    foreach ($chunk as $row) {
                        $existing[$row->entity_id][$row->language_code][$row->field_name] = (string) $row->translated_value;
                    }
                });

            $rowsWithGaps = 0;
            $dispatched = 0;
            // chunkById requires the order-by column in the SELECT, but
            // the rest of the work only needs the fields we may translate.
            $selectColumns = array_values(array_unique(array_merge([$primaryKey], $fields)));
            if (! in_array($primaryKey, $selectColumns, true)) {
                array_unshift($selectColumns, $primaryKey);
            }

            DB::table($table)
                ->select($selectColumns)
                ->orderBy($primaryKey)
                ->chunkById(500, function ($chunk) use (
                    $entityType,
                    $fields,
                    $source,
                    $targets,
                    $existing,
                    $overwrite,
                    $dryRun,
                    $limit,
                    $primaryKey,
                    &$rowsWithGaps,
                    &$dispatched
                ): void {
                    foreach ($chunk as $row) {
                        $rowId = (int) ($row->{$primaryKey} ?? 0);
                        if ($rowId <= 0) {
                            continue;
                        }

                        $missingValues = [];
                        foreach ($fields as $field) {
                            $sourceValue = $row->{$field} ?? null;
                            if (! is_string($sourceValue) || trim($sourceValue) === '') {
                                continue;
                            }

                            // If at least one target locale is missing or
                            // empty for this field, include the field. The
                            // downstream TranslateContentJob will translate
                            // into every supported locale once invoked.
                            foreach ($targets as $locale) {
                                $value = $existing[$rowId][$locale][$field] ?? '';
                                if ($overwrite || trim($value) === '') {
                                    $missingValues[$field] = $sourceValue;
                                    break;
                                }
                            }
                        }

                        if ($missingValues === []) {
                            continue;
                        }

                        $rowsWithGaps++;

                        if ($dryRun) {
                            continue;
                        }

                        if ($limit > 0 && $dispatched >= $limit) {
                            continue;
                        }

                        TranslateContentJob::dispatch(
                            $entityType,
                            $rowId,
                            $missingValues,
                            $source,
                        );
                        $dispatched++;
                    }
                }, $primaryKey);

            $this->line(sprintf(
                '  %s: %d rows with gaps | jobs %s: %d',
                $entityType,
                $rowsWithGaps,
                $dryRun ? 'that would be dispatched' : 'dispatched',
                $dryRun ? $rowsWithGaps : $dispatched
            ));

            $totalDispatched += $dryRun ? $rowsWithGaps : $dispatched;
        }

        return $totalDispatched;
    }
}

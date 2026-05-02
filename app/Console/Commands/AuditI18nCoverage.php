<?php

namespace App\Console\Commands;

use App\Models\SupportedLanguage;
use App\Models\UiTranslation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuditI18nCoverage extends Command
{
    protected $signature = 'i18n:audit
        {--lang= : Restrict report to a single language code}
        {--missing : Print the missing keys list for each language}
        {--canonical= : Path to canonical defaults JSON (defaults to database/data/ui_translation_defaults_en.json)}';

    protected $description = 'Report UI translation coverage per supported language and list missing keys.';

    public function handle(): int
    {
        $canonicalPath = (string) ($this->option('canonical') ?? database_path('data/ui_translation_defaults_en.json'));
        if (! File::isFile($canonicalPath)) {
            $this->error('Canonical defaults JSON not found: '.$canonicalPath);

            return self::FAILURE;
        }

        $canonical = json_decode(File::get($canonicalPath), true);
        if (! is_array($canonical)) {
            $this->error('Canonical JSON failed to parse.');

            return self::FAILURE;
        }
        $canonicalKeys = array_keys($canonical);
        $totalCanonical = count($canonicalKeys);

        $this->info("Canonical key count (en): {$totalCanonical}");
        $this->newLine();

        $languages = SupportedLanguage::query()
            ->when($this->option('lang'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('code')
            ->get();

        if ($languages->isEmpty()) {
            $this->warn('No supported languages found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($languages as $lang) {
            $present = UiTranslation::query()
                ->where('language_code', $lang->code)
                ->whereIn('key', $canonicalKeys)
                ->whereNotNull('value')
                ->where('value', '!=', '')
                ->pluck('key')
                ->all();

            $presentCount = count(array_unique($present));
            $missing = array_values(array_diff($canonicalKeys, $present));
            $coverage = $totalCanonical > 0 ? round(($presentCount / $totalCanonical) * 100, 2) : 0;

            $rows[] = [
                'lang' => $lang->code,
                'present' => $presentCount,
                'missing' => count($missing),
                'coverage' => $coverage.'%',
            ];

            if ($this->option('missing') && count($missing) > 0) {
                $this->newLine();
                $this->warn("Missing keys in '{$lang->code}' ({$missing[0]}):");
                foreach (array_slice($missing, 0, 50) as $key) {
                    $this->line("  - {$key}");
                }
                if (count($missing) > 50) {
                    $remaining = count($missing) - 50;
                    $this->line("  ... and {$remaining} more");
                }
            }
        }

        $this->newLine();
        $this->table(['Language', 'Present', 'Missing', 'Coverage'], $rows);

        return self::SUCCESS;
    }
}

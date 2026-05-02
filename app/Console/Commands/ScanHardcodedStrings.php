<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use SplFileInfo;

class ScanHardcodedStrings extends Command
{
    protected $signature = 'i18n:scan-hardcoded
        {--path=app : Directory to scan (relative to base path)}
        {--ext=php : File extension to scan}
        {--min-length=8 : Minimum string length to flag}
        {--limit=100 : Max findings to print}';

    protected $description = 'Heuristic scan for hardcoded user-facing strings in PHP source.';

    /** Patterns inside which strings are likely user-facing (response messages, throw messages, etc.). */
    private const SUSPICIOUS_CONTEXTS = [
        '/->json\s*\(\s*\[\s*[\'"]message[\'"]\s*=>\s*[\'"]([^\'"]{8,})[\'"]/',
        '/throw\s+new\s+\w+Exception\s*\(\s*[\'"]([^\'"]{8,})[\'"]/',
        '/Log::\w+\s*\(\s*[\'"]([^\'"]{8,})[\'"]/',
    ];

    public function handle(): int
    {
        $base = base_path($this->option('path'));
        $ext = (string) $this->option('ext');
        $minLength = (int) $this->option('min-length');
        $limit = (int) $this->option('limit');

        if (! File::isDirectory($base)) {
            $this->error('Directory not found: '.$base);

            return self::FAILURE;
        }

        $findings = [];
        $files = collect(File::allFiles($base))->filter(fn (SplFileInfo $f) => $f->getExtension() === $ext);

        foreach ($files as $file) {
            $contents = File::get($file->getPathname());
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

            foreach (self::SUSPICIOUS_CONTEXTS as $pattern) {
                if (preg_match_all($pattern, $contents, $matches)) {
                    foreach ($matches[1] as $candidate) {
                        if (strlen($candidate) < $minLength) {
                            continue;
                        }
                        // Skip likely non-user-facing
                        if ($this->isFalsePositive($candidate)) {
                            continue;
                        }
                        $findings[] = [
                            'file' => $relative,
                            'candidate' => $candidate,
                        ];
                    }
                }
            }
        }

        if ($findings === []) {
            $this->info('No hardcoded user-facing strings detected.');

            return self::SUCCESS;
        }

        $shown = array_slice($findings, 0, $limit);
        $this->warn(sprintf('Found %d candidate(s)%s:', count($findings), count($findings) > $limit ? ' (showing first '.$limit.')' : ''));
        foreach ($shown as $f) {
            $this->line(sprintf('  %s — "%s"', $f['file'], $f['candidate']));
        }

        return self::SUCCESS;
    }

    private function isFalsePositive(string $candidate): bool
    {
        // Common false positives: file paths, slugs, technical labels
        if (preg_match('#^[\w\-/.\\\\]+$#', $candidate)) {
            return true; // path-like
        }
        if (preg_match('/^[a-z][\w_.]+$/', $candidate)) {
            return true; // identifier-like
        }
        if (str_contains($candidate, '://')) {
            return true; // URL
        }

        return false;
    }
}

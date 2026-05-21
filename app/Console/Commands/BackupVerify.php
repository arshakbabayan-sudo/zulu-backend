<?php

namespace App\Console\Commands;

use App\Services\Monitoring\TelegramAlertService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Daily backup health check (Sprint H4).
 *
 * Runs ~1 hour after the nightly db:backup so a fresh artefact exists.
 * Verifies the most recent dump is:
 *   - present on the configured disk
 *   - large enough to plausibly be a real dump (>1 KiB)
 *   - mtime within the last 26 hours (1h grace beyond the 24h cycle)
 *   - a valid gzip stream (magic bytes 1f 8b)
 *
 * On failure: emits a Telegram alert via the existing bot and exits non-zero
 * so the scheduler logs reflect a real problem. On success: stays silent
 * unless run with --verbose.
 *
 * Why a fresh command instead of expanding HealthCheck: backups live on a
 * disk path (not the DB), and the 24h freshness window has different
 * cooldown semantics than the 10-minute error-rate checks. Keeping them
 * apart means a backup outage cannot be drowned by a chatty error-rate
 * cooldown.
 */
class BackupVerify extends Command
{
    protected $signature = 'backup:verify
        {--disk= : Storage disk to inspect (defaults to DB_BACKUP_DISK env or "local")}
        {--prefix=backups/db : Path prefix to search}
        {--max-age-hours=26 : Hottest backup must be no older than this}
        {--min-bytes=1024 : Hottest backup must be at least this many bytes}
        {--files-dir= : Optional absolute directory to also check (e.g. /root/file-backups)}
        {--files-pattern=zulu-files-*.tar.gz : Glob pattern for the files-dir check}';

    protected $description = 'Verify the most recent DB (and optionally files) backup is fresh + intact';

    public function handle(TelegramAlertService $alerts): int
    {
        $disk = (string) ($this->option('disk') ?: env('DB_BACKUP_DISK', 'local'));
        $prefix = trim((string) $this->option('prefix'), '/');
        $maxAgeHours = max(1, (int) $this->option('max-age-hours'));
        $minBytes = max(1, (int) $this->option('min-bytes'));

        $problems = [];

        // ---- DB backup verification ---------------------------------------
        $dbFinding = $this->verifyDbBackup($disk, $prefix, $maxAgeHours, $minBytes);
        if ($dbFinding['problem'] !== null) {
            $problems[] = $dbFinding['problem'];
        }
        $this->line($dbFinding['summary']);

        // ---- Optional files backup verification ---------------------------
        $filesDir = (string) ($this->option('files-dir') ?: env('FILES_BACKUP_DIR', ''));
        if ($filesDir !== '') {
            $filesFinding = $this->verifyFilesBackup(
                $filesDir,
                (string) $this->option('files-pattern'),
                $maxAgeHours,
                $minBytes,
            );
            if ($filesFinding['problem'] !== null) {
                $problems[] = $filesFinding['problem'];
            }
            $this->line($filesFinding['summary']);
        }

        // ---- Alert + exit -------------------------------------------------
        if ($problems === []) {
            return self::SUCCESS;
        }

        $hostname = gethostname();
        $body = "🚨 <b>ZULU backup verification FAILED</b> @ {$hostname}\n\n".implode("\n\n", $problems);
        $alerts->send($body);

        foreach ($problems as $line) {
            $this->error($line);
        }

        return self::FAILURE;
    }

    /**
     * @return array{problem: ?string, summary: string}
     */
    private function verifyDbBackup(string $disk, string $prefix, int $maxAgeHours, int $minBytes): array
    {
        try {
            $storage = Storage::disk($disk);
        } catch (\Throwable $e) {
            return [
                'problem' => "DB backup disk '{$disk}' unreachable: {$e->getMessage()}",
                'summary' => "❌ DB backup disk error: {$e->getMessage()}",
            ];
        }

        $files = collect($storage->files($prefix))
            ->filter(fn (string $f) => str_ends_with(strtolower($f), '.sql.gz'))
            ->values();

        if ($files->isEmpty()) {
            return [
                'problem' => "DB backup directory `{$prefix}` on disk `{$disk}` contains no .sql.gz files.",
                'summary' => "❌ No DB backup files found on disk={$disk} prefix={$prefix}",
            ];
        }

        $latest = $files
            ->map(fn (string $f) => ['path' => $f, 'mtime' => $storage->lastModified($f)])
            ->sortByDesc('mtime')
            ->first();

        $latestPath = $latest['path'];
        $latestMtime = Carbon::createFromTimestamp($latest['mtime']);
        $ageHours = $latestMtime->diffInMinutes(Carbon::now()) / 60;
        $size = $storage->size($latestPath);

        $problems = [];
        if ($ageHours > $maxAgeHours) {
            $problems[] = sprintf(
                'too old (%.1fh, threshold %dh)',
                $ageHours,
                $maxAgeHours,
            );
        }
        if ($size < $minBytes) {
            $problems[] = sprintf('too small (%d bytes, threshold %d)', $size, $minBytes);
        }

        // gzip magic check — read only the first 4 bytes via the disk's read API.
        // For local disks this is a tiny seek; for remote (S3) it's an HTTP Range
        // read in any well-behaved adapter.
        $magicProblem = $this->checkGzipMagic($storage, $latestPath);
        if ($magicProblem !== null) {
            $problems[] = $magicProblem;
        }

        if ($problems !== []) {
            return [
                'problem' => "DB backup `{$latestPath}` failed checks: ".implode(', ', $problems),
                'summary' => "❌ DB backup {$latestPath}: ".implode(', ', $problems),
            ];
        }

        return [
            'problem' => null,
            'summary' => sprintf(
                '✅ DB backup OK: %s (%.1fh old, %d bytes)',
                $latestPath,
                $ageHours,
                $size,
            ),
        ];
    }

    /**
     * @return array{problem: ?string, summary: string}
     */
    private function verifyFilesBackup(string $dir, string $pattern, int $maxAgeHours, int $minBytes): array
    {
        if (! is_dir($dir)) {
            return [
                'problem' => "Files backup directory `{$dir}` does not exist on host.",
                'summary' => "❌ Files backup dir missing: {$dir}",
            ];
        }

        $matches = glob(rtrim($dir, '/').'/'.$pattern) ?: [];
        if ($matches === []) {
            return [
                'problem' => "Files backup directory `{$dir}` has no matches for `{$pattern}`.",
                'summary' => "❌ No files-backup matches in {$dir} pattern={$pattern}",
            ];
        }

        usort($matches, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));
        $latest = $matches[0];
        $mtime = Carbon::createFromTimestamp((int) filemtime($latest));
        $ageHours = $mtime->diffInMinutes(Carbon::now()) / 60;
        $size = (int) filesize($latest);

        $problems = [];
        if ($ageHours > $maxAgeHours) {
            $problems[] = sprintf('too old (%.1fh, threshold %dh)', $ageHours, $maxAgeHours);
        }
        if ($size < $minBytes) {
            $problems[] = sprintf('too small (%d bytes, threshold %d)', $size, $minBytes);
        }

        // gzip magic on the tar.gz too — same magic bytes.
        $magic = @file_get_contents($latest, false, null, 0, 4);
        if (! is_string($magic) || strlen($magic) < 2 || ord($magic[0]) !== 0x1f || ord($magic[1]) !== 0x8b) {
            $problems[] = 'not a valid gzip stream (bad magic bytes)';
        }

        if ($problems !== []) {
            return [
                'problem' => "Files backup `{$latest}` failed checks: ".implode(', ', $problems),
                'summary' => "❌ Files backup ".basename($latest).': '.implode(', ', $problems),
            ];
        }

        return [
            'problem' => null,
            'summary' => sprintf(
                '✅ Files backup OK: %s (%.1fh old, %d bytes)',
                basename($latest),
                $ageHours,
                $size,
            ),
        ];
    }

    private function checkGzipMagic(\Illuminate\Contracts\Filesystem\Filesystem $storage, string $path): ?string
    {
        try {
            $stream = $storage->readStream($path);
            if ($stream === null) {
                return 'unable to open backup for read';
            }
            $head = fread($stream, 4);
            fclose($stream);
        } catch (\Throwable $e) {
            return 'read error: '.$e->getMessage();
        }

        if (! is_string($head) || strlen($head) < 2 || ord($head[0]) !== 0x1f || ord($head[1]) !== 0x8b) {
            return 'not a valid gzip stream (bad magic bytes)';
        }

        return null;
    }
}

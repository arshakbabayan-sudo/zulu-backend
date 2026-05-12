<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Database backup (Sprint 54, PART 32; off-site disk support added 2026-05-12).
 *
 * Runs pg_dump against the configured pgsql connection, gzips the output,
 * stores it on the configured disk, and prunes backups older than the
 * retention window. Wired into the scheduler at 02:30 daily.
 *
 * Disks supported:
 *   - `local` (default) — writes to storage/app/private/{prefix}
 *   - `backup` (off-site) — writes to an S3-compatible bucket
 *     (Backblaze B2 / Hetzner Object Storage / Wasabi / R2). See
 *     config/filesystems.php "backup" disk for the env-var contract.
 *
 * Remote-disk path: stream pg_dump → gzip → local tmp file, then
 * upload via Storage::writeStream() and delete the tmp. Keeps memory
 * flat regardless of dump size.
 */
class DatabaseBackup extends Command
{
    protected $signature = 'db:backup
        {--disk=local : Storage disk where backups are written}
        {--keep=14 : Days of backups to retain (older files are pruned)}
        {--prefix=backups/db : Path prefix on the disk}';

    protected $description = 'Dump the PostgreSQL database to a gzipped SQL file and prune old backups';

    public function handle(): int
    {
        $config = config('database.connections.'.config('database.default'));

        if (($config['driver'] ?? null) !== 'pgsql') {
            $this->error('db:backup currently supports pgsql only.');

            return self::FAILURE;
        }

        $disk = (string) $this->option('disk');
        $keep = max(1, (int) $this->option('keep'));
        $prefix = trim((string) $this->option('prefix'), '/');

        $timestamp = Carbon::now('UTC')->format('Ymd-His');
        $database = (string) ($config['database'] ?? 'zulu');
        $relativePath = "{$prefix}/{$database}-{$timestamp}.sql.gz";

        $storage = Storage::disk($disk);
        $isLocal = $this->isLocalDisk($storage);

        // Local: gzip straight to the destination file. Remote: gzip to
        // a tmp file, then upload via stream.
        $tmpPath = $isLocal
            ? $storage->path($relativePath)
            : tempnam(sys_get_temp_dir(), 'zulu-db-backup-');

        if ($tmpPath === false) {
            $this->error('Cannot allocate a temporary file for the backup.');

            return self::FAILURE;
        }

        if ($isLocal) {
            $directory = dirname($tmpPath);
            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                $this->error("Cannot create backup directory: {$directory}");

                return self::FAILURE;
            }
        }

        $this->info("Backing up {$database} → [{$disk}] {$relativePath}");

        $command = [
            'pg_dump',
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 5432),
            '--username='.($config['username'] ?? 'postgres'),
            '--no-password',
            '--format=plain',
            '--no-owner',
            '--no-privileges',
            $database,
        ];

        $env = ['PGPASSWORD' => (string) ($config['password'] ?? '')];
        $startedAt = microtime(true);

        $dump = new Process($command, null, $env, null, 3600);
        $dump->start();

        $gzipHandle = gzopen($tmpPath, 'wb6');
        if ($gzipHandle === false) {
            $this->error("Cannot open gzip file for writing: {$tmpPath}");
            $dump->stop();
            if (! $isLocal) {
                @unlink($tmpPath);
            }

            return self::FAILURE;
        }

        foreach ($dump as $type => $chunk) {
            if ($type === Process::OUT) {
                gzwrite($gzipHandle, $chunk);
            } elseif ($type === Process::ERR && $chunk !== '') {
                $this->line(rtrim($chunk), 'comment');
            }
        }

        gzclose($gzipHandle);

        if (! $dump->isSuccessful()) {
            @unlink($tmpPath);
            $this->error('pg_dump failed: '.$dump->getErrorOutput());

            return self::FAILURE;
        }

        $size = filesize($tmpPath) ?: 0;

        if (! $isLocal) {
            $stream = fopen($tmpPath, 'rb');
            if ($stream === false) {
                @unlink($tmpPath);
                $this->error("Cannot open tmp file for upload: {$tmpPath}");

                return self::FAILURE;
            }

            try {
                $storage->writeStream($relativePath, $stream, ['visibility' => 'private']);
            } catch (\Throwable $e) {
                @fclose($stream);
                @unlink($tmpPath);
                $this->error("Upload to [{$disk}] failed: ".$e->getMessage());

                return self::FAILURE;
            }

            @fclose($stream);
            @unlink($tmpPath);
        }

        $elapsed = number_format(microtime(true) - $startedAt, 2);
        $this->info(sprintf('Backup written (%s, %0.2f MB) in %ss', basename($relativePath), $size / 1048576, $elapsed));

        $this->pruneOldBackups($storage, $prefix, $keep, $database);

        $this->logBackupRecord($disk, $database, $relativePath, $size);

        return self::SUCCESS;
    }

    private function pruneOldBackups(Filesystem $storage, string $prefix, int $keep, string $database): void
    {
        $cutoff = Carbon::now('UTC')->subDays($keep);
        $files = $storage->files($prefix);

        $removed = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if (! str_starts_with($name, $database.'-') || ! str_ends_with($name, '.sql.gz')) {
                continue;
            }

            $modified = $storage->lastModified($file);
            if ($modified === false) {
                continue;
            }

            if ($modified < $cutoff->getTimestamp()) {
                $storage->delete($file);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->info("Pruned {$removed} backups older than {$keep} day(s).");
        }
    }

    private function logBackupRecord(string $disk, string $database, string $path, int $size): void
    {
        try {
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'category' => 'system',
                'actor_type' => 'system',
                'actor_id' => null,
                'actor_name_snapshot' => 'db:backup',
                'subject_type' => 'database',
                'subject_id' => $database,
                'action' => 'database.backup.completed',
                'changes' => json_encode(['disk' => $disk, 'path' => $path, 'size_bytes' => $size]),
                'context' => null,
                'ip_address' => null,
                'user_agent' => null,
                'session_id' => null,
                'request_id' => null,
                'hash' => hash('sha256', $disk.'|'.$path.'|'.$size.'|'.now()),
                'previous_log_hash' => null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->warn('Could not write audit_log row: '.$e->getMessage());
        }
    }

    private function isLocalDisk(Filesystem $storage): bool
    {
        // Filesystem::path() exists only on local adapters; remote
        // adapters throw RuntimeException. Probe via has-method check.
        try {
            $storage->path('');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}

<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Database backup (Sprint 54, PART 32).
 *
 * Runs pg_dump against the configured pgsql connection, gzips the output,
 * stores it on the configured disk, and prunes backups older than the
 * retention window. Wired into the scheduler at 02:30 daily.
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
        $absolutePath = Storage::disk($disk)->path($relativePath);

        $directory = dirname($absolutePath);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("Cannot create backup directory: {$directory}");

            return self::FAILURE;
        }

        $this->info("Backing up {$database} → {$relativePath}");

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

        $gzipPath = $absolutePath;
        $gzipHandle = gzopen($gzipPath, 'wb6');
        if ($gzipHandle === false) {
            $this->error("Cannot open gzip file for writing: {$gzipPath}");
            $dump->stop();

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
            @unlink($gzipPath);
            $this->error('pg_dump failed: '.$dump->getErrorOutput());

            return self::FAILURE;
        }

        $size = filesize($gzipPath) ?: 0;
        $elapsed = number_format(microtime(true) - $startedAt, 2);
        $this->info(sprintf('Backup written (%s, %0.2f MB) in %ss', basename($relativePath), $size / 1048576, $elapsed));

        $this->pruneOldBackups($disk, $prefix, $keep, $database);

        $this->logBackupRecord($database, $relativePath, $size);

        return self::SUCCESS;
    }

    private function pruneOldBackups(string $disk, string $prefix, int $keep, string $database): void
    {
        $cutoff = Carbon::now('UTC')->subDays($keep);
        $files = Storage::disk($disk)->files($prefix);

        $removed = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if (! str_starts_with($name, $database.'-') || ! str_ends_with($name, '.sql.gz')) {
                continue;
            }

            $modified = Storage::disk($disk)->lastModified($file);
            if ($modified === false) {
                continue;
            }

            if ($modified < $cutoff->getTimestamp()) {
                Storage::disk($disk)->delete($file);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->info("Pruned {$removed} backups older than {$keep} day(s).");
        }
    }

    private function logBackupRecord(string $database, string $path, int $size): void
    {
        try {
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'category' => 'system',
                'actor_type' => 'system',
                'actor_id' => null,
                'actor_name_snapshot' => 'db:backup',
                'subject_type' => null,
                'subject_id' => $database,
                'action' => 'database.backup.completed',
                'changes' => json_encode(['path' => $path, 'size_bytes' => $size]),
                'context' => null,
                'ip_address' => null,
                'user_agent' => null,
                'session_id' => null,
                'request_id' => null,
                'hash' => hash('sha256', $path.'|'.$size.'|'.now()),
                'previous_log_hash' => null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->warn('Could not write audit_log row: '.$e->getMessage());
        }
    }
}

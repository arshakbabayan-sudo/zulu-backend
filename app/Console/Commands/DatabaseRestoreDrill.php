<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Database restore drill (Sprint 77, PART 32).
 *
 * Verifies the most recent gzipped pg_dump backup is restorable.
 *
 * Strategy:
 *   1. Find the newest backups/db/zulu-*.sql.gz file on the configured disk.
 *   2. Drop + recreate a scratch database (default: <db>_drill).
 *   3. Restore the gzipped dump into the scratch DB.
 *   4. Run a sanity SELECT (count of public tables).
 *   5. Drop the scratch DB on success (unless --keep is given).
 *
 * Default behavior is `--dry-run`: only checks file presence, gzip validity,
 * and prints metadata. Pass --execute to actually create + restore the
 * scratch DB. Audit-logged either way.
 */
class DatabaseRestoreDrill extends Command
{
    protected $signature = 'db:restore-drill
        {--disk=local : Storage disk where backups live}
        {--prefix=backups/db : Path prefix on the disk}
        {--scratch-db= : Override scratch DB name (default: <db>_drill)}
        {--execute : Actually run the restore (default is dry-run)}
        {--keep : Do not drop the scratch DB after a successful drill}';

    protected $description = 'Verify the most recent DB backup is restorable by replaying it into a scratch database';

    public function handle(): int
    {
        $config = config('database.connections.'.config('database.default'));

        if (($config['driver'] ?? null) !== 'pgsql') {
            $this->error('db:restore-drill currently supports pgsql only.');

            return self::FAILURE;
        }

        $disk = (string) $this->option('disk');
        $prefix = trim((string) $this->option('prefix'), '/');
        $database = (string) ($config['database'] ?? 'zulu');
        $scratchDb = (string) ($this->option('scratch-db') ?: $database.'_drill');
        $execute = (bool) $this->option('execute');
        $keep = (bool) $this->option('keep');

        // Step 1: locate latest backup
        $files = Storage::disk($disk)->files($prefix);
        $matches = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (str_starts_with($name, $database.'-') && str_ends_with($name, '.sql.gz')) {
                $matches[] = $file;
            }
        }

        if ($matches === []) {
            $this->error("No backup files found at {$disk}:{$prefix}/{$database}-*.sql.gz");
            $this->logAudit('failed', null, ['reason' => 'no_backup_found']);

            return self::FAILURE;
        }

        usort($matches, fn ($a, $b) => Storage::disk($disk)->lastModified($b) <=> Storage::disk($disk)->lastModified($a));
        $latest = $matches[0];
        $absolutePath = Storage::disk($disk)->path($latest);
        $sizeBytes = filesize($absolutePath) ?: 0;
        $modifiedAt = Carbon::createFromTimestamp(Storage::disk($disk)->lastModified($latest))->toIso8601String();

        $this->info("Latest backup: {$latest}");
        $this->info(sprintf('  size: %0.2f MB', $sizeBytes / 1048576));
        $this->info("  modified: {$modifiedAt}");

        // Step 2: gzip integrity check
        $gzipOk = $this->checkGzipIntegrity($absolutePath);
        if (! $gzipOk) {
            $this->error('gzip integrity check FAILED — backup is corrupt');
            $this->logAudit('failed', $latest, ['reason' => 'gzip_corrupt']);

            return self::FAILURE;
        }
        $this->info('  gzip integrity: OK');

        if (! $execute) {
            $this->warn('Dry-run only. Pass --execute to actually replay the dump into a scratch DB.');
            $this->logAudit('dry_run_ok', $latest, [
                'size_bytes' => $sizeBytes,
                'modified_at' => $modifiedAt,
            ]);

            return self::SUCCESS;
        }

        // Step 3: scratch DB recreate
        $this->info("Recreating scratch DB: {$scratchDb}");
        $this->dropScratchDb($config, $scratchDb);
        if (! $this->createScratchDb($config, $scratchDb)) {
            $this->error('Failed to create scratch DB');
            $this->logAudit('failed', $latest, ['reason' => 'create_scratch_failed']);

            return self::FAILURE;
        }

        // Step 4: restore
        $startedAt = microtime(true);
        $this->info('Restoring dump into scratch DB…');
        if (! $this->restoreInto($config, $scratchDb, $absolutePath)) {
            $this->error('Restore failed');
            if (! $keep) {
                $this->dropScratchDb($config, $scratchDb);
            }
            $this->logAudit('failed', $latest, ['reason' => 'restore_failed']);

            return self::FAILURE;
        }
        $elapsed = number_format(microtime(true) - $startedAt, 2);
        $this->info("Restore OK in {$elapsed}s");

        // Step 5: sanity check
        $tableCount = $this->scratchDbTableCount($config, $scratchDb);
        $this->info("Public tables in scratch DB: {$tableCount}");

        if ($tableCount === 0) {
            $this->error('Scratch DB has 0 public tables — restore appears empty');
            if (! $keep) {
                $this->dropScratchDb($config, $scratchDb);
            }
            $this->logAudit('failed', $latest, ['reason' => 'empty_after_restore']);

            return self::FAILURE;
        }

        if (! $keep) {
            $this->dropScratchDb($config, $scratchDb);
            $this->info("Dropped scratch DB {$scratchDb}");
        } else {
            $this->info("Scratch DB {$scratchDb} kept (use --keep=false to auto-drop)");
        }

        $this->logAudit('ok', $latest, [
            'size_bytes' => $sizeBytes,
            'restore_seconds' => (float) $elapsed,
            'public_tables' => $tableCount,
        ]);

        $this->info('✓ Restore drill PASSED');

        return self::SUCCESS;
    }

    private function checkGzipIntegrity(string $path): bool
    {
        $process = new Process(['gzip', '-t', $path]);
        $process->run();

        return $process->isSuccessful();
    }

    private function dropScratchDb(array $config, string $scratchDb): void
    {
        // Force-disconnect any open connections to the scratch DB.
        $process = $this->psqlProcess($config, [
            '-c', "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='{$scratchDb}' AND pid <> pg_backend_pid();",
        ]);
        $process->run();

        $process = $this->psqlProcess($config, ['-c', "DROP DATABASE IF EXISTS {$scratchDb};"]);
        $process->run();
    }

    private function createScratchDb(array $config, string $scratchDb): bool
    {
        $owner = (string) ($config['username'] ?? 'postgres');
        $process = $this->psqlProcess($config, ['-c', "CREATE DATABASE {$scratchDb} OWNER {$owner};"]);
        $process->run();

        return $process->isSuccessful();
    }

    private function restoreInto(array $config, string $scratchDb, string $gzipPath): bool
    {
        $cmd = sprintf(
            'gunzip -c %s | PGPASSWORD=%s psql -h %s -p %s -U %s -d %s -v ON_ERROR_STOP=1',
            escapeshellarg($gzipPath),
            escapeshellarg((string) ($config['password'] ?? '')),
            escapeshellarg((string) ($config['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($config['port'] ?? 5432)),
            escapeshellarg((string) ($config['username'] ?? 'postgres')),
            escapeshellarg($scratchDb),
        );

        $process = Process::fromShellCommandline($cmd, null, null, null, 3600);
        $process->run();
        if (! $process->isSuccessful()) {
            $this->line($process->getErrorOutput(), 'comment');

            return false;
        }

        return true;
    }

    private function scratchDbTableCount(array $config, string $scratchDb): int
    {
        $process = $this->psqlProcess(array_merge($config, ['database' => $scratchDb]), [
            '-tA',
            '-c',
            "SELECT count(*) FROM pg_tables WHERE schemaname='public';",
        ]);
        $process->run();

        return (int) trim($process->getOutput());
    }

    private function psqlProcess(array $config, array $extra): Process
    {
        $args = array_merge([
            'psql',
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 5432),
            '--username='.($config['username'] ?? 'postgres'),
            '--no-password',
            '-d', $config['database'] ?? 'postgres',
        ], $extra);

        return new Process($args, null, ['PGPASSWORD' => (string) ($config['password'] ?? '')], null, 600);
    }

    private function logAudit(string $outcome, ?string $backupPath, array $extra): void
    {
        try {
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'category' => 'system',
                'actor_type' => 'system',
                'actor_id' => null,
                'actor_name_snapshot' => 'db:restore-drill',
                'subject_type' => 'database',
                'subject_id' => $backupPath ?? 'unknown',
                'action' => 'database.restore_drill.'.$outcome,
                'changes' => json_encode($extra),
                'context' => null,
                'ip_address' => null,
                'user_agent' => null,
                'session_id' => null,
                'request_id' => null,
                'hash' => hash('sha256', $outcome.'|'.($backupPath ?? '').'|'.now()),
                'previous_log_hash' => null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->warn('Could not write audit_log row: '.$e->getMessage());
        }
    }
}

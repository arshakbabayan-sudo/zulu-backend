<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprint H4 — daily backup verification command.
 */
class BackupVerifyCommandTest extends TestCase
{
    private string $diskName = 'backup_test';

    private string $prefix = 'backups/db';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake($this->diskName);
    }

    public function test_passes_when_recent_valid_gzip_dump_present(): void
    {
        Storage::disk($this->diskName)->put(
            $this->prefix.'/zulu-20260521-023001.sql.gz',
            // gzip magic 1f 8b + dummy padding to clear the min-bytes threshold
            "\x1f\x8b".str_repeat('x', 4096),
        );

        $this->artisan('backup:verify', [
            '--disk' => $this->diskName,
            '--prefix' => $this->prefix,
        ])->assertExitCode(0);
    }

    public function test_fails_when_directory_is_empty(): void
    {
        $this->artisan('backup:verify', [
            '--disk' => $this->diskName,
            '--prefix' => $this->prefix,
        ])->assertExitCode(1);
    }

    public function test_fails_when_latest_dump_too_small(): void
    {
        Storage::disk($this->diskName)->put(
            $this->prefix.'/zulu-20260521-023001.sql.gz',
            "\x1f\x8b", // valid magic but only 2 bytes
        );

        $this->artisan('backup:verify', [
            '--disk' => $this->diskName,
            '--prefix' => $this->prefix,
            '--min-bytes' => 1024,
        ])->assertExitCode(1);
    }

    public function test_fails_when_latest_dump_has_bad_gzip_magic(): void
    {
        Storage::disk($this->diskName)->put(
            $this->prefix.'/zulu-20260521-023001.sql.gz',
            'this is plain text, not gzip — pretending to be a dump'.str_repeat('-', 2000),
        );

        $this->artisan('backup:verify', [
            '--disk' => $this->diskName,
            '--prefix' => $this->prefix,
        ])->assertExitCode(1);
    }

    public function test_fails_when_latest_dump_too_old(): void
    {
        $path = $this->prefix.'/zulu-20260101-023001.sql.gz';
        Storage::disk($this->diskName)->put(
            $path,
            "\x1f\x8b".str_repeat('x', 4096),
        );

        // Backdate the file by force — Storage::fake exposes the underlying
        // local adapter at storage/framework/testing/disks/<diskName>.
        $localPath = Storage::disk($this->diskName)->path($path);
        $longAgo = strtotime('-3 days');
        touch($localPath, $longAgo, $longAgo);

        $this->artisan('backup:verify', [
            '--disk' => $this->diskName,
            '--prefix' => $this->prefix,
            '--max-age-hours' => 26,
        ])->assertExitCode(1);
    }

    public function test_files_backup_check_passes_when_present_and_recent(): void
    {
        $tmpDir = sys_get_temp_dir().'/zulu-test-files-'.uniqid();
        mkdir($tmpDir, 0700, true);
        $tarPath = $tmpDir.'/zulu-files-2026-05-21.tar.gz';
        file_put_contents($tarPath, "\x1f\x8b".str_repeat('y', 4096));

        // DB side still needs to pass — write a valid gzip there too.
        Storage::disk($this->diskName)->put(
            $this->prefix.'/zulu-20260521-023001.sql.gz',
            "\x1f\x8b".str_repeat('x', 4096),
        );

        try {
            $this->artisan('backup:verify', [
                '--disk' => $this->diskName,
                '--prefix' => $this->prefix,
                '--files-dir' => $tmpDir,
            ])->assertExitCode(0);
        } finally {
            @unlink($tarPath);
            @rmdir($tmpDir);
        }
    }

    public function test_files_backup_check_fails_when_directory_missing(): void
    {
        Storage::disk($this->diskName)->put(
            $this->prefix.'/zulu-20260521-023001.sql.gz',
            "\x1f\x8b".str_repeat('x', 4096),
        );

        $this->artisan('backup:verify', [
            '--disk' => $this->diskName,
            '--prefix' => $this->prefix,
            '--files-dir' => '/nonexistent/path/'.uniqid(),
        ])->assertExitCode(1);
    }
}

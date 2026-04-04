<?php

namespace App\Services\Imports;

use App\Models\ImportSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class ImportSessionService
{
    /**
     * @param  array<string, mixed>|null  $extraOptions  Reserved for future options merged into options_json.
     */
    public function createFromAuthenticatedUpload(
        User $user,
        int $companyId,
        UploadedFile $file,
        string $templateVersion,
        bool $dryRun,
        string $syncMode,
        ?array $extraOptions = null,
    ): ImportSession {
        $disk = 'local';
        $relativeDir = sprintf('imports/%d/%s', $companyId, now()->format('Y/m'));
        $storedPath = $file->store($relativeDir, $disk);

        $realPath = $file->getRealPath();
        $checksum = $realPath ? hash_file('sha256', $realPath) : null;

        $optionsJson = $extraOptions !== null && $extraOptions !== [] ? $extraOptions : null;

        return ImportSession::query()->create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'template_version' => $templateVersion,
            'file_disk' => $disk,
            'file_path' => $storedPath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_checksum' => $checksum,
            'options_json' => $optionsJson,
            'dry_run' => $dryRun,
            'sync_mode' => $syncMode,
            'status' => ImportSession::STATUS_UPLOADED,
            'rows_total' => 0,
            'rows_valid' => 0,
            'rows_invalid' => 0,
            'entities_created' => 0,
            'entities_updated' => 0,
            'entities_skipped' => 0,
            'entities_failed' => 0,
            'validation_errors_count' => 0,
            'commit_errors_count' => 0,
            'error_report_path' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    /**
     * Coerce types after HTTP validation (multipart booleans, optional sync_mode).
     *
     * @param  array{template_version: string, dry_run?: bool|string|null, sync_mode?: string|null}  $validated
     * @return array{template_version: string, dry_run: bool, sync_mode: string}
     */
    public function normalizeOptions(array $validated): array
    {
        $dryRaw = $validated['dry_run'] ?? false;
        $dryRun = filter_var($dryRaw, FILTER_VALIDATE_BOOLEAN);

        return [
            'template_version' => $validated['template_version'],
            'dry_run' => $dryRun,
            'sync_mode' => $validated['sync_mode'] ?? ImportSession::SYNC_MODE_PARTIAL,
        ];
    }
}

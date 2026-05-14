<?php

namespace App\Console\Commands;

use App\Models\DataExportRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredDataExports extends Command
{
    protected $signature = 'accounts:purge-expired-data-exports';

    protected $description = 'GDPR — drop ZIP archives whose 7-day download window has elapsed.';

    public function handle(): int
    {
        $expired = DataExportRequest::query()
            ->whereIn('status', [DataExportRequest::STATUS_READY])
            ->where('expires_at', '<', now())
            ->get();

        $purged = 0;
        foreach ($expired as $row) {
            if ($row->file_path && Storage::disk('local')->exists($row->file_path)) {
                Storage::disk('local')->delete($row->file_path);
            }
            $row->update([
                'status' => DataExportRequest::STATUS_EXPIRED,
                'download_token' => null,
                'file_path' => null,
            ]);
            $purged++;
        }

        $this->info(sprintf('Expired %d export archive(s).', $purged));

        return self::SUCCESS;
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase Է — Google Drive-hosted file metadata on file_assets.
 *
 * A FileAsset row can now be backed by EITHER local/S3 disk (legacy
 * personal files, super-admin files) OR a file inside a company's own
 * Google Drive. The two new columns let the download/delete code tell
 * which backend to use:
 *
 *   - drive_file_id        non-null → file lives in the company's Drive
 *                          (under that company's ZULU_Spin_Files folder)
 *   - drive_web_view_link  optional Drive UI URL surfaced to the file
 *                          manager so "Open in Drive" works
 *
 * If both `path` and `drive_file_id` are set, drive_file_id wins —
 * happens during the local→Drive migration window after a company
 * first connects (handled by MigrateCompanyFilesToDrive job, deferred).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_assets', function (Blueprint $table): void {
            $table->string('drive_file_id', 64)->nullable()->after('path');
            $table->text('drive_web_view_link')->nullable()->after('drive_file_id');

            $table->index('drive_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('file_assets', function (Blueprint $table): void {
            $table->dropIndex(['drive_file_id']);
            $table->dropColumn(['drive_file_id', 'drive_web_view_link']);
        });
    }
};

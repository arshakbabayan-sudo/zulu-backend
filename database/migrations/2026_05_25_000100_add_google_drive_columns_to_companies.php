<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase Է — multi-tenant Google Drive integration.
 *
 * Each company connects its own Google Drive via the new
 * `/api/auth/google-drive/*` OAuth flow (separate from the Phase 8
 * social-login flow on /api/auth/oauth/*). The three columns below
 * hold the per-company state needed to upload files into that
 * company's own Drive on its members' behalf.
 *
 *   - google_access_token   short-lived OAuth bearer (≈1h); refreshed
 *                           on demand from google_refresh_token. Stored
 *                           encrypted via the model cast.
 *   - google_refresh_token  long-lived offline token captured on first
 *                           connect (requires access_type=offline +
 *                           prompt=consent on the redirect). Encrypted.
 *   - google_drive_folder_id  ID of the per-company root folder
 *                             "ZULU_Spin_Files" created automatically
 *                             on first connect; all file uploads for
 *                             this company land underneath it.
 *
 * Tokens are kept on the COMPANY (the tenant), not on individual users.
 * Any company admin can disconnect/reconnect; existing files keep their
 * own drive_file_id until manually moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // text + nullable so we can clear them on disconnect; encrypted
            // at the model layer (`encrypted` cast in App\Models\Company).
            $table->text('google_access_token')->nullable()->after('archived_reason');
            $table->text('google_refresh_token')->nullable()->after('google_access_token');
            $table->unsignedInteger('google_token_expires_at')->nullable()->after('google_refresh_token');
            $table->string('google_drive_folder_id', 64)->nullable()->after('google_token_expires_at');
            $table->timestamp('google_drive_connected_at')->nullable()->after('google_drive_folder_id');
            $table->string('google_drive_connected_email', 255)->nullable()->after('google_drive_connected_at');

            // Indexed so the FileAsset gate (`whereNull(google_drive_folder_id)`)
            // and admin "which companies have Drive linked" reports are cheap.
            $table->index('google_drive_folder_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['google_drive_folder_id']);
            $table->dropColumn([
                'google_access_token',
                'google_refresh_token',
                'google_token_expires_at',
                'google_drive_folder_id',
                'google_drive_connected_at',
                'google_drive_connected_email',
            ]);
        });
    }
};

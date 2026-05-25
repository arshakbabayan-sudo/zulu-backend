<?php

namespace App\Services\GoogleDrive;

use App\Models\Company;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Drive\DriveFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Google Drive integration — Phase Է, 2026-05-25.
 *
 * Tenant model: every Company row owns its own Drive tokens. This service
 * is stateless across companies: every public method takes a Company and
 * builds a fresh authenticated client for it. Tokens auto-refresh and
 * persist back to the row on refresh.
 *
 * Scope: drive.file only — we never touch files we didn't create. The
 * per-company root folder "ZULU_Spin_Files" is the only Drive object we
 * see across sessions; everything else is reached via stored file IDs.
 *
 * Failure mode: any unrecoverable error throws GoogleDriveException so
 * the caller (controller / upload endpoint) can map it to a 422/503
 * response instead of bubbling raw Google\Service\Exception traces.
 */
class GoogleDriveService
{
    /**
     * Build a Google\Client bound to the company's tokens. Auto-refreshes
     * the access token (and persists it) when it has expired.
     *
     * @throws GoogleDriveException if the company has no refresh token
     *                              or the refresh exchange fails.
     */
    public function clientFor(Company $company): GoogleClient
    {
        if ($company->google_refresh_token === null || $company->google_refresh_token === '') {
            throw new GoogleDriveException('Company has not connected Google Drive yet');
        }

        $client = $this->makeBaseClient();

        $client->setAccessToken([
            'access_token' => (string) ($company->google_access_token ?? ''),
            'refresh_token' => (string) $company->google_refresh_token,
            'expires_in' => max(0, ((int) ($company->google_token_expires_at ?? 0)) - time()),
            'created' => max(0, ((int) ($company->google_token_expires_at ?? 0)) - 3600),
        ]);

        if ($client->isAccessTokenExpired()) {
            $this->refreshAccessToken($client, $company);
        }

        return $client;
    }

    /**
     * Create the per-company root folder "ZULU_Spin_Files" if it does
     * not yet exist, and persist its ID on the company row. Idempotent:
     * if the company already has a folder_id we trust it (caller can
     * call disconnect → reconnect to recreate).
     *
     * @throws GoogleDriveException
     */
    public function ensureRootFolder(Company $company): string
    {
        if ($company->google_drive_folder_id !== null && $company->google_drive_folder_id !== '') {
            return $company->google_drive_folder_id;
        }

        $client = $this->clientFor($company);
        $drive = new GoogleDrive($client);

        $folderName = (string) config('services.google_drive.root_folder_name', 'ZULU_Spin_Files');

        try {
            $folder = new DriveFile([
                'name' => $folderName,
                'mimeType' => 'application/vnd.google-apps.folder',
            ]);

            $created = $drive->files->create($folder, ['fields' => 'id, webViewLink']);
        } catch (\Throwable $e) {
            throw new GoogleDriveException('Failed to create root folder: '.$e->getMessage(), 0, $e);
        }

        $company->forceFill([
            'google_drive_folder_id' => $created->getId(),
            'google_drive_connected_at' => now(),
        ])->save();

        return (string) $created->getId();
    }

    /**
     * Upload a file into the company's Drive (under the root folder).
     * Returns the created Drive file ID + a web view link the file
     * manager can surface as "Open in Drive".
     *
     * @return array{id:string, web_view_link:?string, size:int}
     *
     * @throws GoogleDriveException
     */
    public function uploadFile(Company $company, UploadedFile $file, ?string $subfolderName = null): array
    {
        $rootFolderId = $this->ensureRootFolder($company);
        $client = $this->clientFor($company);
        $drive = new GoogleDrive($client);

        $parentId = $rootFolderId;
        if ($subfolderName !== null && $subfolderName !== '' && $subfolderName !== '/') {
            $parentId = $this->ensureSubfolder($drive, $rootFolderId, $subfolderName);
        }

        try {
            $driveFile = new DriveFile([
                'name' => $file->getClientOriginalName() ?: 'file',
                'parents' => [$parentId],
            ]);

            $bytes = file_get_contents($file->getRealPath());
            if ($bytes === false) {
                throw new RuntimeException('Failed to read uploaded file');
            }

            $created = $drive->files->create($driveFile, [
                'data' => $bytes,
                'mimeType' => $file->getMimeType() ?: 'application/octet-stream',
                'uploadType' => 'multipart',
                'fields' => 'id, webViewLink, size',
            ]);
        } catch (\Throwable $e) {
            throw new GoogleDriveException('Failed to upload to Drive: '.$e->getMessage(), 0, $e);
        }

        return [
            'id' => (string) $created->getId(),
            'web_view_link' => $created->getWebViewLink(),
            'size' => (int) ($created->getSize() ?? $file->getSize() ?? 0),
        ];
    }

    /**
     * Stream the raw bytes of a Drive-hosted file. Returns a resource
     * suitable for piping into StreamedResponse.
     *
     * @return array{stream:resource, mime_type:string, size:int, name:string}
     *
     * @throws GoogleDriveException
     */
    public function downloadFile(Company $company, string $driveFileId): array
    {
        $client = $this->clientFor($company);
        $drive = new GoogleDrive($client);

        try {
            $meta = $drive->files->get($driveFileId, ['fields' => 'id, name, mimeType, size']);
            $response = $drive->files->get($driveFileId, ['alt' => 'media']);
        } catch (\Throwable $e) {
            throw new GoogleDriveException('Failed to fetch Drive file: '.$e->getMessage(), 0, $e);
        }

        $body = $response->getBody();
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new GoogleDriveException('Failed to open temp stream');
        }
        fwrite($stream, (string) $body);
        rewind($stream);

        return [
            'stream' => $stream,
            'mime_type' => (string) $meta->getMimeType(),
            'size' => (int) ($meta->getSize() ?? 0),
            'name' => (string) $meta->getName(),
        ];
    }

    /**
     * Delete a file from the company's Drive. Swallows "not found" so
     * the local row can still be soft-deleted when the file is already
     * gone on the Drive side.
     */
    public function deleteFile(Company $company, string $driveFileId): void
    {
        try {
            $client = $this->clientFor($company);
            $drive = new GoogleDrive($client);
            $drive->files->delete($driveFileId);
        } catch (\Throwable $e) {
            Log::warning('Google Drive deletion failed (best effort)', [
                'company_id' => $company->id,
                'drive_file_id' => $driveFileId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build a raw OAuth client (no tokens) used by the auth controller
     * for the redirect → callback exchange.
     */
    public function makeBaseClient(): GoogleClient
    {
        $clientId = (string) config('services.google_drive.client_id', '');
        $clientSecret = (string) config('services.google_drive.client_secret', '');
        $redirectUri = (string) config('services.google_drive.redirect_uri', '');

        if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
            throw new GoogleDriveException('Google Drive credentials are not configured');
        }

        $client = new GoogleClient;
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->setScopes([GoogleDrive::DRIVE_FILE]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    /**
     * Persist the tokens returned by the OAuth callback exchange onto
     * the company row. Called once at the end of the consent flow.
     */
    public function persistTokens(Company $company, array $tokens, ?string $connectedEmail): void
    {
        $expiresIn = (int) ($tokens['expires_in'] ?? 3600);
        $refreshToken = $tokens['refresh_token'] ?? $company->google_refresh_token;

        if ($refreshToken === null || $refreshToken === '') {
            throw new GoogleDriveException(
                'Google did not return a refresh_token — ensure access_type=offline + prompt=consent are set'
            );
        }

        $company->forceFill([
            'google_access_token' => (string) ($tokens['access_token'] ?? ''),
            'google_refresh_token' => (string) $refreshToken,
            'google_token_expires_at' => time() + $expiresIn,
            'google_drive_connected_email' => $connectedEmail,
        ])->save();
    }

    /**
     * Wipe the tokens + root folder id when an admin disconnects. We
     * intentionally do NOT delete the folder from Drive — that data
     * belongs to the company, not us.
     */
    public function disconnect(Company $company): void
    {
        $company->forceFill([
            'google_access_token' => null,
            'google_refresh_token' => null,
            'google_token_expires_at' => null,
            'google_drive_folder_id' => null,
            'google_drive_connected_at' => null,
            'google_drive_connected_email' => null,
        ])->save();
    }

    /* ────────────────────────────────────────────────────────────── */

    /**
     * Find or create a subfolder under the company's root folder by name.
     * The file manager stores files under nested folders ("/contracts",
     * "/passports", etc.) — we mirror that as one Drive folder per name.
     */
    private function ensureSubfolder(GoogleDrive $drive, string $rootFolderId, string $name): string
    {
        $name = trim($name, '/');
        if ($name === '') {
            return $rootFolderId;
        }

        // First segment only — nested paths get flattened to a single
        // folder level on Drive; this matches the file manager's
        // shallow "folder" column model.
        $segment = explode('/', $name)[0];
        $safeSegment = str_replace("'", "\\'", $segment);

        try {
            $list = $drive->files->listFiles([
                'q' => sprintf(
                    "mimeType='application/vnd.google-apps.folder' and trashed=false and '%s' in parents and name='%s'",
                    str_replace("'", "\\'", $rootFolderId),
                    $safeSegment
                ),
                'fields' => 'files(id,name)',
                'pageSize' => 1,
            ]);

            $existing = $list->getFiles();
            if (! empty($existing)) {
                return (string) $existing[0]->getId();
            }

            $folder = new DriveFile([
                'name' => $segment,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [$rootFolderId],
            ]);

            $created = $drive->files->create($folder, ['fields' => 'id']);

            return (string) $created->getId();
        } catch (\Throwable $e) {
            throw new GoogleDriveException('Failed to ensure subfolder: '.$e->getMessage(), 0, $e);
        }
    }

    private function refreshAccessToken(GoogleClient $client, Company $company): void
    {
        try {
            $refreshed = $client->fetchAccessTokenWithRefreshToken((string) $company->google_refresh_token);
        } catch (\Throwable $e) {
            throw new GoogleDriveException('Refresh token exchange failed: '.$e->getMessage(), 0, $e);
        }

        if (isset($refreshed['error'])) {
            throw new GoogleDriveException(
                'Refresh token rejected by Google: '.((string) ($refreshed['error_description'] ?? $refreshed['error']))
            );
        }

        $expiresIn = (int) ($refreshed['expires_in'] ?? 3600);

        $company->forceFill([
            'google_access_token' => (string) ($refreshed['access_token'] ?? ''),
            'google_token_expires_at' => time() + $expiresIn,
            // Google sometimes rotates the refresh token; if so, persist it.
            'google_refresh_token' => (string) ($refreshed['refresh_token'] ?? $company->google_refresh_token),
        ])->save();
    }
}

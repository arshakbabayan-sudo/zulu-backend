<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * File manager — backend API (Phase Ե, 2026-05-25).
 *
 *   GET    /api/files                  — list files in a folder (scoped by visibility)
 *   POST   /api/files/upload           — multipart upload onto Laravel's default disk
 *   GET    /api/files/{id}/download    — stream bytes for an asset the user can access
 *   DELETE /api/files/{id}             — soft-delete the row + delete bytes from disk
 *   POST   /api/files/folder           — create a folder marker (.folder zero-byte)
 *   GET    /api/files/storage-stats    — totals + per-mime-bucket breakdown
 *
 * Storage backend: Laravel's default disk (env FILESYSTEM_DISK). Default is
 * `local` → storage/app/private. Switching to S3 later = just change the env
 * var; nothing in this controller assumes a specific driver.
 *
 * Visibility model:
 *   - private  → only uploader + super-admin can see/download
 *   - company  → users with membership in company_id (+ super-admin) can access
 *   - public   → any authenticated user can access
 *
 * Files are NEVER served by direct symlink — every download flows through
 * /api/files/{id}/download so the visibility check applies.
 */
class FileAssetController extends Controller
{
    /** Hard-block these dangerous extensions even if MIME validation passes. */
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'phps', 'pht',
        'exe', 'bat', 'cmd', 'com', 'sh', 'bash',
        'msi', 'scr', 'cpl', 'pif', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh',
        'ps1', 'psm1', 'jar',
    ];

    /** GET /api/files?folder=/&company_id=N */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folder' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer'],
        ]);

        $folder = $this->normalizeFolder($validated['folder'] ?? '/');
        $user = $request->user();

        $query = FileAsset::query()
            ->where('folder', $folder)
            ->where('filename', '!=', FileAsset::FOLDER_MARKER)
            ->orderBy('filename');

        $this->scopeByAccess($query, $user, $validated['company_id'] ?? null);

        $rows = $query->limit(500)->get();

        // Also surface the immediate sub-folders under this folder so the UI
        // can render a folder grid. A subfolder exists if there's a file asset
        // whose `folder` starts with the current folder + "/".
        $subfolders = $this->listSubfolders($folder, $user);

        return response()->json([
            'success' => true,
            'data' => [
                'folder' => $folder,
                'files' => $rows->map(fn (FileAsset $f) => $this->toRow($f))->all(),
                'subfolders' => $subfolders,
            ],
        ]);
    }

    /** POST /api/files/upload */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:51200'], // 50 MB in KB
            'folder' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer'],
            'visibility' => ['nullable', 'string', Rule::in(FileAsset::VISIBILITIES)],
        ]);

        $folder = $this->normalizeFolder($validated['folder'] ?? '/');
        $visibility = $validated['visibility'] ?? 'private';
        $companyId = $validated['company_id'] ?? null;

        $user = $request->user();

        // For company visibility, require membership (unless super-admin).
        if ($companyId !== null && ! $user->is_super_admin && ! $user->belongsToCompany((int) $companyId)) {
            return response()->json(['success' => false, 'message' => 'Not a member of that company'], 403);
        }

        $uploaded = $request->file('file');
        $ext = strtolower($uploaded->getClientOriginalExtension() ?: $uploaded->extension() ?: '');

        if ($ext !== '' && in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            return response()->json([
                'success' => false,
                'message' => "File extension .{$ext} is not allowed",
            ], 422);
        }

        $disk = (string) config('filesystems.default', 'local');
        $stamp = now()->format('Ymd_His');
        $safeName = Str::slug(pathinfo($uploaded->getClientOriginalName(), PATHINFO_FILENAME), '_')
            ?: 'file';
        $storedName = $stamp.'_'.Str::random(8).($ext !== '' ? ".{$ext}" : '');
        $storedDir = 'file-manager/'.trim($folder, '/');
        if ($storedDir === 'file-manager/') {
            $storedDir = 'file-manager';
        }

        $path = Storage::disk($disk)->putFileAs($storedDir, $uploaded, $storedName);

        if ($path === false) {
            return response()->json(['success' => false, 'message' => 'Upload failed'], 500);
        }

        $asset = FileAsset::create([
            'uploaded_by' => $user->id,
            'company_id' => $companyId,
            'folder' => $folder,
            'filename' => $uploaded->getClientOriginalName() ?: $storedName,
            'mime_type' => $uploaded->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $uploaded->getSize() ?: 0,
            'disk' => $disk,
            'path' => $path,
            'visibility' => $visibility,
            'metadata' => [
                'original_name' => $uploaded->getClientOriginalName(),
                'extension' => $ext,
                'uploaded_via' => 'admin-file-manager',
                'safe_slug' => $safeName,
            ],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->toRow($asset),
        ], 201);
    }

    /** GET /api/files/{id}/download */
    public function download(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $asset = FileAsset::find($id);
        if (! $asset) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        if (! $this->userCanAccess($request->user(), $asset)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $disk = Storage::disk($asset->disk);
        if (! $disk->exists($asset->path)) {
            return response()->json(['success' => false, 'message' => 'File missing on storage'], 410);
        }

        return $disk->download($asset->path, $asset->filename, [
            'Content-Type' => $asset->mime_type,
        ]);
    }

    /** DELETE /api/files/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $asset = FileAsset::find($id);
        if (! $asset) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $user = $request->user();
        // Delete permission = uploader OR super-admin OR (company-scoped with company membership).
        $canDelete = ((int) $asset->uploaded_by === (int) $user->id)
            || $user->is_super_admin
            || ($asset->company_id !== null && $user->belongsToCompany((int) $asset->company_id));

        if (! $canDelete) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        try {
            Storage::disk($asset->disk)->delete($asset->path);
        } catch (\Throwable $e) {
            // We still soft-delete the row even if the bytes are already gone.
        }

        $asset->delete();

        return response()->json(['success' => true, 'data' => ['id' => $id]]);
    }

    /** POST /api/files/folder — create a folder marker. */
    public function createFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folder' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer'],
            'visibility' => ['nullable', 'string', Rule::in(FileAsset::VISIBILITIES)],
        ]);

        $folder = $this->normalizeFolder($validated['folder']);
        if ($folder === '/') {
            return response()->json(['success' => false, 'message' => 'Cannot create root folder'], 422);
        }

        $user = $request->user();
        $companyId = $validated['company_id'] ?? null;
        $visibility = $validated['visibility'] ?? 'private';

        if ($companyId !== null && ! $user->is_super_admin && ! $user->belongsToCompany((int) $companyId)) {
            return response()->json(['success' => false, 'message' => 'Not a member of that company'], 403);
        }

        $exists = FileAsset::where('folder', $folder)
            ->where('filename', FileAsset::FOLDER_MARKER)
            ->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Folder already exists'], 409);
        }

        $disk = (string) config('filesystems.default', 'local');
        $storedDir = 'file-manager/'.trim($folder, '/');
        $markerPath = $storedDir.'/'.FileAsset::FOLDER_MARKER;
        Storage::disk($disk)->put($markerPath, '');

        $asset = FileAsset::create([
            'uploaded_by' => $user->id,
            'company_id' => $companyId,
            'folder' => $folder,
            'filename' => FileAsset::FOLDER_MARKER,
            'mime_type' => 'application/x-zulu-folder',
            'size_bytes' => 0,
            'disk' => $disk,
            'path' => $markerPath,
            'visibility' => $visibility,
            'metadata' => ['is_folder' => true],
        ]);

        return response()->json([
            'success' => true,
            'data' => ['folder' => $folder, 'id' => $asset->id],
        ], 201);
    }

    /** GET /api/files/storage-stats — total + by mime bucket. */
    public function storageStats(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = FileAsset::query()->where('filename', '!=', FileAsset::FOLDER_MARKER);
        $this->scopeByAccess($query, $user, null);

        $rows = $query->get(['mime_type', 'size_bytes']);

        $total = 0;
        $byBucket = [
            'image' => ['count' => 0, 'bytes' => 0],
            'application' => ['count' => 0, 'bytes' => 0],
            'video' => ['count' => 0, 'bytes' => 0],
            'audio' => ['count' => 0, 'bytes' => 0],
            'text' => ['count' => 0, 'bytes' => 0],
            'other' => ['count' => 0, 'bytes' => 0],
        ];

        foreach ($rows as $r) {
            $prefix = strtok((string) $r->mime_type, '/') ?: 'other';
            $bucket = match ($prefix) {
                'image', 'video', 'audio', 'text', 'application' => $prefix,
                default => 'other',
            };
            $size = (int) $r->size_bytes;
            $byBucket[$bucket]['count']++;
            $byBucket[$bucket]['bytes'] += $size;
            $total += $size;
        }

        // Reasonable per-user quota for the UI progress bar. 10 GB matches the
        // mockup; admin can raise via env var later.
        $quotaBytes = (int) (env('FILE_MANAGER_QUOTA_GB', 10)) * 1024 * 1024 * 1024;

        return response()->json([
            'success' => true,
            'data' => [
                'total_bytes' => $total,
                'total_count' => $rows->count(),
                'quota_bytes' => $quotaBytes,
                'by_bucket' => $byBucket,
            ],
        ]);
    }

    /* ─── helpers ────────────────────────────────────────────────────── */

    /**
     * Apply visibility-based access scoping.
     *
     *   asset is visible if:
     *     - super-admin → always
     *     - uploaded_by = self
     *     - company_id ∈ user's memberships
     *     - visibility = public
     */
    private function scopeByAccess(Builder $query, $user, ?int $companyIdHint): void
    {
        if ($user?->is_super_admin) {
            if ($companyIdHint !== null) {
                $query->where(function (Builder $q) use ($companyIdHint) {
                    $q->where('company_id', $companyIdHint)->orWhereNull('company_id');
                });
            }
            return;
        }

        $companyIds = $user->memberships()->pluck('company_id')->all();
        $userId = (int) $user->id;

        $query->where(function (Builder $q) use ($userId, $companyIds, $companyIdHint) {
            $q->where('uploaded_by', $userId)
                ->orWhere('visibility', 'public');

            if (! empty($companyIds)) {
                $q->orWhereIn('company_id', $companyIds);
            }

            if ($companyIdHint !== null && in_array($companyIdHint, $companyIds, true)) {
                $q->orWhere('company_id', $companyIdHint);
            }
        });
    }

    private function userCanAccess($user, FileAsset $asset): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->is_super_admin) {
            return true;
        }
        if ((int) $asset->uploaded_by === (int) $user->id) {
            return true;
        }
        if ($asset->visibility === 'public') {
            return true;
        }
        if ($asset->company_id !== null && $user->belongsToCompany((int) $asset->company_id)) {
            return true;
        }
        return false;
    }

    /**
     * Normalize a folder path: must start with '/', no '..', no trailing slash
     * (except root).
     */
    private function normalizeFolder(string $folder): string
    {
        $folder = trim($folder);
        if ($folder === '' || $folder === '/') {
            return '/';
        }
        if ($folder[0] !== '/') {
            $folder = '/'.$folder;
        }
        // Collapse multiple slashes, strip '..' segments.
        $segments = array_values(array_filter(
            explode('/', $folder),
            fn ($s) => $s !== '' && $s !== '..' && $s !== '.'
        ));
        return '/'.implode('/', $segments);
    }

    /**
     * Surface immediate sub-folders under $folder. Cheap implementation: pull
     * distinct `folder` values that start with the prefix and extract the next
     * segment. We cap at 500 rows considered to keep things bounded.
     *
     * @return list<array{folder:string,name:string,file_count:int,total_bytes:int}>
     */
    private function listSubfolders(string $folder, $user): array
    {
        $prefix = $folder === '/' ? '/' : $folder.'/';

        $query = FileAsset::query()->where('folder', 'like', $prefix.'%');
        $this->scopeByAccess($query, $user, null);

        $rows = $query->get(['folder', 'filename', 'size_bytes']);

        /** @var array<string, array{name:string, folder:string, file_count:int, total_bytes:int}> $map */
        $map = [];
        foreach ($rows as $r) {
            $rel = substr($r->folder, strlen($prefix));
            if ($rel === false || $rel === '') {
                continue;
            }
            $name = explode('/', $rel)[0];
            if ($name === '') {
                continue;
            }
            $subPath = $prefix === '/' ? '/'.$name : $prefix.$name;
            if (! isset($map[$subPath])) {
                $map[$subPath] = [
                    'folder' => $subPath,
                    'name' => $name,
                    'file_count' => 0,
                    'total_bytes' => 0,
                ];
            }
            if ($r->filename !== FileAsset::FOLDER_MARKER) {
                $map[$subPath]['file_count']++;
                $map[$subPath]['total_bytes'] += (int) $r->size_bytes;
            }
        }

        return array_values($map);
    }

    /**
     * Shape a FileAsset row for the API response.
     *
     * @return array<string, mixed>
     */
    private function toRow(FileAsset $f): array
    {
        return [
            'id' => $f->id,
            'uploaded_by' => $f->uploaded_by,
            'company_id' => $f->company_id,
            'folder' => $f->folder,
            'filename' => $f->filename,
            'mime_type' => $f->mime_type,
            'size_bytes' => (int) $f->size_bytes,
            'visibility' => $f->visibility,
            'metadata' => $f->metadata,
            'created_at' => optional($f->created_at)->toIso8601String(),
            'updated_at' => optional($f->updated_at)->toIso8601String(),
        ];
    }
}

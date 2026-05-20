<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Media\ImageSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Authenticated image upload endpoint.
 *
 *   POST /api/media/upload
 *
 *   multipart/form-data:
 *     file:    required, image (jpg/jpeg/png/webp), ≤ 10 MB
 *     section: required, one of MediaUploadController::SECTIONS
 *
 *   200 OK:
 *     { url: "https://api.zulu.am/storage/uploads/hotels/<uuid>.jpg",
 *       path: "uploads/hotels/<uuid>.jpg",
 *       size_bytes: 384921,
 *       mime: "image/jpeg" }
 *
 * Files land on the `public` disk under uploads/{section}/. The
 * `php artisan storage:link` symlink (config/filesystems.php links[])
 * exposes them at /storage/uploads/... — the URL returned here is
 * absolute so the admin form can store it directly in the database
 * column (hotels.main_image, packages.main_image, etc.) without any
 * post-processing.
 */
class MediaUploadController extends Controller
{
    /** @var list<string> */
    public const SECTIONS = [
        'hotels',
        'flights',
        'transfers',
        'cars',
        'excursions',
        'visas',
        'packages',
        'offers',
        'banners',
        'companies',
        'users',
    ];

    public function upload(Request $request, ImageSanitizer $sanitizer): JsonResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:10240', // 10 MB in KB
            ],
            'section' => ['required', 'string', Rule::in(self::SECTIONS)],
        ]);

        $section = $validated['section'];
        $file = $request->file('file');

        $ext = $file->getClientOriginalExtension() ?: $file->extension();
        $ext = strtolower($ext ?: 'jpg');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }

        // Re-encode through GD so EXIF GPS coordinates / camera fingerprints
        // never reach the public disk. The sanitized file replaces $file.
        $file = $sanitizer->stripExif($file);

        $name = Str::uuid()->toString().'.'.$ext;
        $path = "uploads/{$section}/{$name}";

        Storage::disk('public')->putFileAs("uploads/{$section}", $file, $name, ['visibility' => 'public']);

        $url = rtrim((string) config('app.url'), '/').'/storage/'.$path;

        return response()->json([
            'success' => true,
            'data' => [
                'url' => $url,
                'path' => $path,
                'size_bytes' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ],
        ]);
    }
}

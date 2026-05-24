<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * file_assets row — see 2026_05_25_000010_create_file_assets_table migration
 * for the schema rationale (disk + path stored explicitly so we can swap
 * FILESYSTEM_DISK later without rewriting metadata).
 *
 * @property int $id
 * @property int $uploaded_by
 * @property int|null $company_id
 * @property string $folder
 * @property string $filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $disk
 * @property string $path
 * @property string $visibility
 * @property array<string,mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class FileAsset extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** @var list<string> */
    public const VISIBILITIES = ['private', 'company', 'public'];

    /** Special filename used as a folder marker (zero-byte placeholder). */
    public const FOLDER_MARKER = '.folder';

    protected $fillable = [
        'uploaded_by',
        'company_id',
        'folder',
        'filename',
        'mime_type',
        'size_bytes',
        'disk',
        'path',
        'visibility',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size_bytes' => 'integer',
        'company_id' => 'integer',
        'uploaded_by' => 'integer',
    ];

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** Top-level mime bucket (image, application, video, audio, text, other). */
    public function mimeBucket(): string
    {
        $prefix = strtok($this->mime_type ?? '', '/') ?: 'other';
        return match ($prefix) {
            'image', 'video', 'audio', 'text', 'application' => $prefix,
            default => 'other',
        };
    }

    public function isFolderMarker(): bool
    {
        return $this->filename === self::FOLDER_MARKER;
    }
}

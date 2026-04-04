<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportSession extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_VALIDATION_FAILED = 'validation_failed';

    public const STATUS_STAGING = 'staging';

    public const STATUS_STAGED = 'staged';

    public const STATUS_COMMITTING = 'committing';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_COMMIT_FAILED = 'commit_failed';

    public const STATUS_PARTIALLY_COMMITTED = 'partially_committed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_UPLOADED,
        self::STATUS_VALIDATING,
        self::STATUS_VALIDATED,
        self::STATUS_VALIDATION_FAILED,
        self::STATUS_STAGING,
        self::STATUS_STAGED,
        self::STATUS_COMMITTING,
        self::STATUS_COMMITTED,
        self::STATUS_COMMIT_FAILED,
        self::STATUS_PARTIALLY_COMMITTED,
    ];

    public const SYNC_MODE_PARTIAL = 'partial';

    public const SYNC_MODE_FULL = 'full';

    /** @var list<string> */
    public const SYNC_MODES = [
        self::SYNC_MODE_PARTIAL,
        self::SYNC_MODE_FULL,
    ];

    protected $fillable = [
        'company_id',
        'user_id',
        'template_version',
        'file_disk',
        'file_path',
        'original_filename',
        'mime_type',
        'file_checksum',
        'options_json',
        'dry_run',
        'sync_mode',
        'status',
        'rows_total',
        'rows_valid',
        'rows_invalid',
        'entities_created',
        'entities_updated',
        'entities_skipped',
        'entities_failed',
        'validation_errors_count',
        'commit_errors_count',
        'error_report_path',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'options_json' => 'array',
            'dry_run' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stagingRows(): HasMany
    {
        return $this->hasMany(ImportStagingRow::class, 'import_session_id');
    }

    public function chunkCheckpoints(): HasMany
    {
        return $this->hasMany(ImportChunkCheckpoint::class, 'import_session_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataExportRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    /** Download URL valid for this many days once the ZIP is ready. */
    public const LINK_LIFETIME_DAYS = 7;

    /** Per-user throttle window. */
    public const REQUEST_WINDOW_DAYS = 30;

    protected $fillable = [
        'user_id',
        'status',
        'download_token',
        'file_size_bytes',
        'file_path',
        'ready_at',
        'expires_at',
        'downloaded_at',
        'failure_reason',
    ];

    protected $casts = [
        'ready_at' => 'datetime',
        'expires_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'file_size_bytes' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

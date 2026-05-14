<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountDeletionRequest extends Model
{
    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const GRACE_DAYS = 30;

    protected $fillable = [
        'user_id',
        'status',
        'confirmation_token',
        'confirmation_sent_at',
        'confirmed_at',
        'scheduled_for',
        'cancelled_at',
        'completed_at',
        'reason',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'confirmation_sent_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}

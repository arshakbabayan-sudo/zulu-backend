<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false; // Only created_at, immutable.

    public const CATEGORIES = [
        'auth',
        'data_change',
        'financial',
        'approval',
        'contract',
        'support',
        'admin_actions',
        'api',
        'security',
        'system',
        'error',
    ];

    public const ERROR_SEVERITIES = ['info', 'warning', 'error', 'critical'];

    public const ACTOR_TYPES = ['user', 'system', 'api_client'];

    protected $fillable = [
        'category',
        'actor_type',
        'actor_id',
        'actor_name_snapshot',
        'subject_type',
        'subject_id',
        'action',
        'changes',
        'context',
        'ip_address',
        'user_agent',
        'session_id',
        'request_id',
        'hash',
        'previous_log_hash',
        'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

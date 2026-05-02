<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackageBookingSaga extends Model
{
    use HasUuids;

    protected $table = 'package_booking_sagas';

    protected $keyType = 'string';

    public $incrementing = false;

    public const STATUSES = [
        'pending',
        'reserving',
        'reserved',
        'confirming',
        'confirmed',
        'failed',
        'rolling_back',
        'rolled_back',
        'refunded',
    ];

    protected $fillable = [
        'order_id',
        'package_id',
        'status',
        'started_at',
        'reserved_at',
        'confirmed_at',
        'failed_at',
        'rolled_back_at',
        'failure_reason',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
        'started_at' => 'datetime',
        'reserved_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'failed_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(SagaComponentState::class, 'saga_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SagaStateLog::class, 'saga_id')->orderBy('happened_at');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['confirmed', 'failed', 'rolled_back', 'refunded'], true);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'confirmed';
    }
}

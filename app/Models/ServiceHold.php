<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ServiceHold extends Model
{
    protected $fillable = [
        'holdable_type',
        'holdable_id',
        'user_id',
        'booking_id',
        'quantity',
        'expires_at',
        'released',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'released' => 'boolean',
        'quantity' => 'integer',
    ];

    public function holdable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('released', false)
            ->where('expires_at', '>', now());
    }
}


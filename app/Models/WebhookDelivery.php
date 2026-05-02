<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $table = 'webhook_deliveries';

    public const STATUSES = ['pending', 'success', 'failed'];

    protected $fillable = [
        'subscription_id',
        'event',
        'idempotency_key',
        'payload',
        'status',
        'http_status',
        'response_excerpt',
        'error_message',
        'attempt_count',
        'first_attempted_at',
        'last_attempted_at',
        'succeeded_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'first_attempted_at' => 'datetime',
        'last_attempted_at' => 'datetime',
        'succeeded_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }
}

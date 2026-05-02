<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebhookSubscription extends Model
{
    use SoftDeletes;

    protected $table = 'webhook_subscriptions';

    public const SUPPORTED_EVENTS = [
        'order.paid',
        'order.confirmed',
        'order.failed',
        'voucher.issued',
        'voucher.voided',
        'contract.sent',
        'contract.signed',
        'contract.terminated',
        'connection.proposed',
        'connection.accepted',
        'connection.terminated',
        'package_saga.confirmed',
        'package_saga.failed',
    ];

    protected $fillable = [
        'company_id',
        'target_url',
        'secret',
        'events',
        'description',
        'active',
        'last_succeeded_at',
        'last_failed_at',
        'failure_count',
    ];

    protected $casts = [
        'events' => 'array',
        'active' => 'boolean',
        'last_succeeded_at' => 'datetime',
        'last_failed_at' => 'datetime',
    ];

    protected $hidden = ['secret']; // never expose

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }

    public function subscribesTo(string $event): bool
    {
        return $this->active && in_array($event, $this->events ?? [], true);
    }
}

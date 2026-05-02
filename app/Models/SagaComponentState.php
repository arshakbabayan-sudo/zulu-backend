<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SagaComponentState extends Model
{
    protected $table = 'saga_component_states';

    public const STATUSES = ['pending', 'reserving', 'reserved', 'confirmed', 'failed', 'rolled_back'];

    public const SERVICE_TYPES = ['flight', 'hotel', 'transfer', 'car', 'excursion', 'visa', 'insurance'];

    protected $fillable = [
        'saga_id',
        'package_component_id',
        'order_item_id',
        'service_type',
        'service_id',
        'status',
        'idempotency_key',
        'supplier_ref',
        'error_message',
        'reservation_payload',
        'attempted_at',
        'reserved_at',
        'confirmed_at',
        'rolled_back_at',
    ];

    protected $casts = [
        'reservation_payload' => 'array',
        'attempted_at' => 'datetime',
        'reserved_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function saga(): BelongsTo
    {
        return $this->belongsTo(PackageBookingSaga::class, 'saga_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function isReserved(): bool
    {
        return $this->status === 'reserved';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}

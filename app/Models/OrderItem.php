<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'order_items';

    protected $keyType = 'string';

    public $incrementing = false;

    public const ITEM_TYPES = [
        'flight',
        'hotel',
        'transfer',
        'car',
        'excursion',
        'visa',
        'insurance',
        'package',
    ];

    public const STATUSES = [
        'pending',
        'reserved',
        'confirmed',
        'cancelled',
        'failed',
    ];

    protected $fillable = [
        'order_id',
        'item_type',
        'item_id',
        'package_id',
        'parent_item_id',
        'quantity',
        'unit_price',
        'total',
        'currency',
        'service_snapshot',
        'passenger_data',
        'date_from',
        'date_to',
        'status',
        'external_ref',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
        'service_snapshot' => 'array',
        'passenger_data' => 'array',
        'date_from' => 'date',
        'date_to' => 'date',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_item_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_item_id');
    }
}

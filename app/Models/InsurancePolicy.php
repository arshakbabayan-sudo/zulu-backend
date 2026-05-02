<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsurancePolicy extends Model
{
    use HasUuids;

    protected $table = 'insurance_policies';

    protected $keyType = 'string';

    public $incrementing = false;

    public const STATUSES = ['active', 'cancelled', 'expired', 'claimed'];

    protected $fillable = [
        'policy_number',
        'product_id',
        'order_id',
        'order_item_id',
        'user_id',
        'insured_persons',
        'coverage_start_date',
        'coverage_end_date',
        'coverage_days',
        'premium_paid',
        'currency',
        'product_snapshot',
        'status',
        'policy_pdf_url',
        'issued_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'insured_persons' => 'array',
        'product_snapshot' => 'array',
        'coverage_start_date' => 'date',
        'coverage_end_date' => 'date',
        'premium_paid' => 'decimal:2',
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(InsuranceProduct::class, 'product_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return $this->coverage_end_date !== null && ! $this->coverage_end_date->isPast();
    }
}

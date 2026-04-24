<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionTransaction extends Model
{
    use HasUuids;

    protected $table = 'commission_transactions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'order_id',
        'order_item_id',
        'rule_id',
        'seller_id',
        'base_amount',
        'base_currency',
        'commission_amount',
        'commission_currency',
        'net_to_seller',
        'fx_rate',
        'snapshot',
        'computed_at',
    ];

    protected $casts = [
        'base_amount' => 'decimal:4',
        'commission_amount' => 'decimal:4',
        'net_to_seller' => 'decimal:4',
        'fx_rate' => 'decimal:8',
        'snapshot' => 'array',
        'computed_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'rule_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'seller_id');
    }

    public function resolutionLogs(): HasMany
    {
        return $this->hasMany(CommissionResolutionLog::class, 'transaction_id');
    }
}

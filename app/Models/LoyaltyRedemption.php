<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRedemption extends Model
{
    protected $table = 'loyalty_redemptions';

    public const STATUSES = ['applied', 'reversed'];

    protected $fillable = [
        'account_id',
        'order_id',
        'points_redeemed',
        'discount_amount',
        'currency',
        'status',
        'redeemed_at',
        'reversed_at',
    ];

    protected $casts = [
        'points_redeemed' => 'integer',
        'discount_amount' => 'decimal:2',
        'redeemed_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}

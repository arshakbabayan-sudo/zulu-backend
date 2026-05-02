<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends Model
{
    protected $table = 'loyalty_transactions';

    public const TYPES = ['earn', 'redeem', 'expire', 'adjust'];

    protected $fillable = [
        'account_id',
        'type',
        'points',
        'source_type',
        'source_id',
        'reason',
        'metadata',
        'happened_at',
    ];

    protected $casts = [
        'points' => 'integer',
        'metadata' => 'array',
        'happened_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'account_id');
    }
}

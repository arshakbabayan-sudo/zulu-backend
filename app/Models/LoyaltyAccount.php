<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyAccount extends Model
{
    protected $table = 'loyalty_accounts';

    public const TIERS = ['bronze', 'silver', 'gold', 'platinum'];

    public const TIER_THRESHOLDS = [
        'bronze' => 0,
        'silver' => 500,
        'gold' => 2500,
        'platinum' => 10000,
    ];

    public const TIER_MULTIPLIERS = [
        'bronze' => 1.0,
        'silver' => 1.25,
        'gold' => 1.5,
        'platinum' => 2.0,
    ];

    public const TYPE_CUSTOMER = 'customer';

    public const TYPE_SELLER = 'seller';

    protected $fillable = [
        'account_type',
        'user_id',
        'company_id',
        'points_balance',
        'lifetime_points',
        'tier',
        'last_activity_at',
    ];

    protected $casts = [
        'points_balance' => 'integer',
        'lifetime_points' => 'integer',
        'last_activity_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class, 'account_id')->orderByDesc('happened_at');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRedemption::class, 'account_id');
    }

    public function tierMultiplier(): float
    {
        return self::TIER_MULTIPLIERS[$this->tier] ?? 1.0;
    }
}

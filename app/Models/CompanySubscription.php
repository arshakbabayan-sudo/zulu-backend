<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7.11 — current subscription row for a company.
 */
class CompanySubscription extends Model
{
    use HasFactory;

    public const STATUSES = ['active', 'cancelled', 'past_due', 'trial'];

    public const BILLING_PERIODS = ['monthly', 'annual'];

    protected $table = 'company_subscriptions';

    protected $fillable = [
        'company_id',
        'plan_id',
        'status',
        'billing_period',
        'period_starts_at',
        'period_ends_at',
        'assigned_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}

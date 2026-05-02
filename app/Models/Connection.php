<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Connection extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'connections';

    protected $keyType = 'string';

    public $incrementing = false;

    public const TYPES = ['supplier_reseller', 'mutual', 'white_label', 'partner_feed'];

    public const DIRECTIONS = ['a_to_b', 'b_to_a', 'both'];

    public const STATUSES = ['proposed', 'active', 'paused', 'terminated', 'rejected'];

    public const SHARE_SCOPE_TYPES = ['all', 'categories', 'services'];

    protected $fillable = [
        'type',
        'seller_a_company_id',
        'seller_b_company_id',
        'direction',
        'partner_agreement_id',
        'commission_override_rule_id',
        'share_scope',
        'territorial_scope',
        'display_options',
        'effective_from',
        'effective_to',
        'status',
        'proposed_at',
        'accepted_at',
        'paused_at',
        'terminated_at',
        'termination_reason',
        'rejection_reason',
        'proposed_by_user_id',
        'responded_by_user_id',
    ];

    protected $casts = [
        'share_scope' => 'array',
        'territorial_scope' => 'array',
        'display_options' => 'array',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'proposed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'paused_at' => 'datetime',
        'terminated_at' => 'datetime',
    ];

    public function sellerA(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'seller_a_company_id');
    }

    public function sellerB(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'seller_b_company_id');
    }

    public function partnerAgreement(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'partner_agreement_id');
    }

    public function commissionOverrideRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_override_rule_id');
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id');
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->effective_to !== null && $this->effective_to->isPast()) {
            return false;
        }

        return true;
    }

    public function isProposed(): bool
    {
        return $this->status === 'proposed';
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    public function isTerminated(): bool
    {
        return $this->status === 'terminated' || $this->status === 'rejected';
    }

    public function involves(int $companyId): bool
    {
        return $this->seller_a_company_id === $companyId || $this->seller_b_company_id === $companyId;
    }

    public function counterpartyOf(int $companyId): ?int
    {
        if ($this->seller_a_company_id === $companyId) {
            return $this->seller_b_company_id;
        }

        if ($this->seller_b_company_id === $companyId) {
            return $this->seller_a_company_id;
        }

        return null;
    }

    public function flowsFrom(int $supplierCompanyId, int $resellerCompanyId): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($this->direction === 'both') {
            return $this->involves($supplierCompanyId) && $this->involves($resellerCompanyId);
        }

        if ($this->direction === 'a_to_b') {
            return $this->seller_a_company_id === $supplierCompanyId
                && $this->seller_b_company_id === $resellerCompanyId;
        }

        if ($this->direction === 'b_to_a') {
            return $this->seller_b_company_id === $supplierCompanyId
                && $this->seller_a_company_id === $resellerCompanyId;
        }

        return false;
    }
}

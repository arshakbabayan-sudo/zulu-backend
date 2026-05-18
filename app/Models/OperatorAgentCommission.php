<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6B — operator → agent commission configuration row.
 *
 * One default row per operator (agent_company_id = null) plus zero or more
 * per-agent override rows. Booking-time logic in Phase 6B part 2 will look
 * up the override first, fall back to default; modules without any row get
 * no agent commission (the operator keeps the full split).
 */
class OperatorAgentCommission extends Model
{
    use HasFactory;

    /** Three modes per the roadmap. */
    public const CALCULATION_BASES = ['gross', 'post_platform_fee', 'custom'];

    protected $table = 'operator_agent_commission';

    protected $fillable = [
        'operator_company_id',
        'agent_company_id',
        'calculation_base',
        'custom_base_percentage',
        'default_percentage',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'custom_base_percentage' => 'decimal:3',
            'default_percentage' => 'decimal:3',
        ];
    }

    public function operatorCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'operator_company_id');
    }

    public function agentCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'agent_company_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Convenience predicate. */
    public function isDefault(): bool
    {
        return $this->agent_company_id === null;
    }
}

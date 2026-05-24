<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 1 / Step C — Pricing rule (markup configuration row).
 *
 * 4-level priority: partnership > operator > category > global.
 * Resolved by App\Services\Pricing\PricingResolver.
 */
class PricingRule extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const SCOPE_PARTNERSHIP = 'partnership';

    public const SCOPE_OPERATOR = 'operator';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_GLOBAL = 'global';

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    protected $table = 'pricing_rules';

    protected $fillable = [
        'scope_type',
        'operator_id',
        'agent_id',
        'service_category',
        'destination_id',
        'markup_type',
        'markup_value',
        'min_sell_amount',
        'max_sell_amount',
        'currency',
        'effective_from',
        'effective_until',
        'priority',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'markup_value' => 'decimal:4',
            'min_sell_amount' => 'decimal:4',
            'max_sell_amount' => 'decimal:4',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'operator_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'agent_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * @param  \Illuminate\Support\Carbon|null  $at
     */
    public function scopeEffectiveAt(Builder $q, $at = null): Builder
    {
        $at = $at ?? now();

        return $q->where('effective_from', '<=', $at)
            ->where(function (Builder $q2) use ($at): void {
                $q2->whereNull('effective_until')
                    ->orWhere('effective_until', '>', $at);
            });
    }

    public function scopeForLevel(Builder $q, string $scopeType): Builder
    {
        return $q->where('scope_type', $scopeType);
    }
}

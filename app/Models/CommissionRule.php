<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommissionRule extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const TYPES = ['percentage', 'fixed', 'hybrid'];

    public const LEVELS = ['global', 'category', 'seller', 'partner_agreement'];

    public const STATUSES = ['active', 'inactive', 'scheduled'];

    public const DIRECTIONS = ['zulu_from_seller', 'seller_from_reseller'];

    protected $table = 'commission_rules';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'type',
        'level',
        'scope_id',
        'service_type',
        'percentage_value',
        'fixed_value',
        'fixed_currency',
        'hybrid_config',
        'tiered_config',
        'direction',
        'priority',
        'effective_from',
        'effective_to',
        'status',
        'active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'percentage_value' => 'decimal:4',
        'fixed_value' => 'decimal:4',
        'hybrid_config' => 'array',
        'tiered_config' => 'array',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'active' => 'boolean',
        'priority' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->where('status', 'active');
    }

    public function scopeEffectiveAt(Builder $query, DateTimeInterface $at): Builder
    {
        return $query
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $query2): Builder => $query2
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $at));
    }

    public function scopeForLevel(Builder $query, string $level): Builder
    {
        return $query->where('level', $level);
    }

    public function scopeForScope(Builder $query, ?int $scopeId): Builder
    {
        return $query->where('scope_id', $scopeId);
    }

    public function scopeMatchingServiceType(Builder $query, string $serviceType): Builder
    {
        return $query->where(fn (Builder $query2): Builder => $query2
            ->whereNull('service_type')
            ->orWhere('service_type', $serviceType));
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

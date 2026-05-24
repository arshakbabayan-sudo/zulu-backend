<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoneyFlowTerm extends Model
{
    use HasFactory;

    public const SCOPE_PARTNERSHIP = 'partnership';

    public const SCOPE_OPERATOR = 'operator';

    public const SCOPE_GLOBAL = 'global';

    public const MODEL_ZULU_COLLECTS = 'zulu_collects';

    public const MODEL_OPERATOR_COLLECTS = 'operator_collects';

    public const MODEL_AGENT_COLLECTS = 'agent_collects';

    protected $table = 'money_flow_terms';

    protected $fillable = [
        'scope_type',
        'operator_id',
        'agent_id',
        'collection_model',
        'remittance_days',
        'invoicing_period',
        'is_active',
        'effective_from',
        'effective_until',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'remittance_days' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
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

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** @param  \Illuminate\Support\Carbon|null  $at */
    public function scopeEffectiveAt(Builder $q, $at = null): Builder
    {
        $at = $at ?? now();

        return $q->where('effective_from', '<=', $at)
            ->where(function (Builder $q2) use ($at): void {
                $q2->whereNull('effective_until')
                    ->orWhere('effective_until', '>', $at);
            });
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase Զ.16 / Item 18 — per-partnership FX premium row.
 */
class FxPartnershipPremium extends Model
{
    use HasFactory;

    protected $table = 'fx_partnership_premiums';

    protected $fillable = [
        'operator_id',
        'agent_id',
        'source_currency',
        'target_currency',
        'premium_percent',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'premium_percent' => 'decimal:2',
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
}

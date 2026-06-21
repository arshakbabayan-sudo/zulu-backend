<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seller FX-rate setting.
 *
 * Per-seller currency configuration for the "seller FX-rate" model: the
 * customer is charged in `target_currency` at a per-unit rate of
 * (base rate + ABSOLUTE margin), where the base rate is either the auto
 * Central-Bank rate (source='cba', read from exchange_rates) or a manual
 * operator-entered rate (source='manual', read from manual_rates).
 *
 * Scope precedence (resolved by SellerFxRateService): agent → operator →
 * platform; first active match wins.
 */
class SellerFxRate extends Model
{
    use HasFactory;

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_OPERATOR = 'operator';

    public const SCOPE_AGENT = 'agent';

    public const SOURCE_CBA = 'cba';

    public const SOURCE_MANUAL = 'manual';

    protected $table = 'seller_fx_rates';

    protected $fillable = [
        'scope',
        'company_id',
        'target_currency',
        'source',
        'margin',
        'manual_rates',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'manual_rates' => 'array',
            'is_active' => 'boolean',
            'margin' => 'decimal:4',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeForTarget(Builder $q, string $targetCurrency): Builder
    {
        return $q->where('target_currency', strtoupper($targetCurrency));
    }
}

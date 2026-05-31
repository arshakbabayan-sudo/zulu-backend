<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-employee compensation config. See migration
 * 2026_06_01_000040_create_crm_employee_compensation_table.php.
 */
class CrmEmployeeCompensation extends Model
{
    protected $table = 'crm_employee_compensation';

    public const MODELS = ['fixed', 'percent', 'fixed_plus_percent'];

    protected $fillable = [
        'user_id',
        'company_id',
        'model',
        'base_amount',
        'commission_percent',
        'currency',
        'notes',
        'updated_by_user_id',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'commission_percent' => 'decimal:3',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Compute payable amount for a given attributed revenue (same currency).
     * base / percent fields are applied per the chosen model.
     */
    public function computePay(float $revenue): float
    {
        $base = (float) $this->base_amount;
        $pct = (float) $this->commission_percent;
        return match ($this->model) {
            'fixed' => $base,
            'percent' => $revenue * $pct / 100,
            default => $base + ($revenue * $pct / 100), // fixed_plus_percent
        };
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform-configurable commission % bounds per service type.
 * See migration 2026_06_01_000140_create_commission_limits_table.php.
 *
 * The 3-step profile-completion wizard validates an operator's chosen commission
 * % against boundsFor($serviceType). A per-type row wins; otherwise the global
 * '*' row applies; if even that is missing, callers fall back to 0–100.
 */
class CommissionLimit extends Model
{
    protected $table = 'commission_limits';

    /** service_type value that holds the global default bounds. */
    public const GLOBAL_KEY = '*';

    /** Service types an operator can set a commission for. */
    public const SERVICE_TYPES = [
        'hotel', 'flight', 'car', 'transfer', 'excursion', 'visa', 'insurance', 'package',
    ];

    protected $fillable = [
        'service_type',
        'min_percent',
        'max_percent',
        'updated_by',
    ];

    protected $casts = [
        'min_percent' => 'float',
        'max_percent' => 'float',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Allowed [min, max] commission % for a service type. Per-type row wins,
     * then the global '*' row, then a hard 0–100 fallback so a missing table
     * never blocks the wizard.
     *
     * @return array{min: float, max: float}
     */
    public static function boundsFor(?string $serviceType): array
    {
        $rows = static::query()
            ->whereIn('service_type', [self::GLOBAL_KEY, (string) $serviceType])
            ->get()
            ->keyBy('service_type');

        $row = $rows->get((string) $serviceType) ?? $rows->get(self::GLOBAL_KEY);

        return [
            'min' => $row?->min_percent ?? 0.0,
            'max' => $row?->max_percent ?? 100.0,
        ];
    }
}

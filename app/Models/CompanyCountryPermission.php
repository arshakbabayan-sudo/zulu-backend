<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-(company, country) seller permission row. Mirrors
 * {@see CompanySellerPermission} for service-type permissions.
 *
 * Used by {@see App\Services\Infrastructure\GeoRestrictionService} —
 * if the service country is not the company's home country, but an
 * active row exists here, the geo check passes.
 */
class CompanyCountryPermission extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_REVOKED,
    ];

    protected $fillable = [
        'company_id',
        'country_code',
        'country_name',
        'status',
        'granted_by',
        'granted_at',
        'notes',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}

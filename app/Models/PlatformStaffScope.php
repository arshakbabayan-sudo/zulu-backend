<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RBAC blueprint Phase 4 — a ZULU platform-staff data assignment.
 *
 * See migration 2026_06_02_000020_create_platform_staff_scopes_table. Each row
 * grants a platform_admin visibility into either ONE company (company_id) or
 * every company in a COUNTRY (country, free-text matching companies.country).
 * Resolved by AdminAccessService::assignedCompanyIds().
 */
class PlatformStaffScope extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_id',
        'country',
        'assigned_by_user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}

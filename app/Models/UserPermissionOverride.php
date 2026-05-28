<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase Գ.6 / Bucket D.4 — per-employee permission override.
 *
 * See migration 2026_05_28_000030_create_user_permission_overrides_table.
 * `effect` is 'allow' (add a permission the role lacks) or 'deny' (remove a
 * permission the role grants). Resolved into an effective set by
 * User::effectivePermissionsForCompany().
 */
class UserPermissionOverride extends Model
{
    use HasFactory;

    public const EFFECT_ALLOW = 'allow';

    public const EFFECT_DENY = 'deny';

    protected $fillable = [
        'user_id',
        'company_id',
        'permission_id',
        'effect',
        'granted_by_user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_COMPANY = 'company';

    protected $fillable = [
        'name',
        'description',
        'scope',
    ];

    /**
     * Convenience: is this role grantable only by a super-admin assigner?
     * Used by the RBAC scope whitelist in role-assignment controllers
     * (Phase 1 / Step B.2).
     */
    public function isPlatformScoped(): bool
    {
        return $this->scope === self::SCOPE_PLATFORM;
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(UserCompany::class);
    }
}

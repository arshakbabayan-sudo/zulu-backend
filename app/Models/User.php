<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements CanResetPasswordContract, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use CanResetPassword, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PENDING_DELETION = 'pending_deletion';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'intended_role',
        'phone',
        'preferred_language',
        'avatar',
        'birth_date',
        'nationality',
        'location',
        'google_id',
        'facebook_id',
        'oauth_provider',
        // GDPR Article 7 consent capture
        'terms_accepted_at',
        'consent_ip',
        'consent_version',
        // 2FA hierarchy (2026-05-31): `method` is the user's chosen channel
        // (`totp`|`email`); `required` is the enforcement flag set on staff
        // accounts at registration and toggled by managers from the admin
        // "Permissions" drawer for employees.
        'two_factor_method',
        'two_factor_required',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'pin_hash',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date',
            'pin_set_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            // GDPR Article 32: PII at-rest encryption. See migration
            // 2026_05_21_000000_encrypt_pii_passport_and_nationality.
            'nationality' => 'encrypted',
            'two_factor_required' => 'boolean',
            'email_verification_code_expires_at' => 'datetime',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'user_company')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    public function belongsToCompany(int $companyId): bool
    {
        return $this->companies()->whereKey($companyId)->exists();
    }

    public function hasCompanyPermission(int $companyId, string $permission): bool
    {
        // Phase Գ.6 / Bucket D.4 — a per-employee override wins over the
        // role-derived baseline: 'deny' revokes, 'allow' grants. No row =
        // pure role behaviour (unchanged for existing tenants).
        $override = $this->permissionOverrides()
            ->where('company_id', $companyId)
            ->whereHas('permission', fn ($q) => $q->where('name', $permission))
            ->value('effect');

        if ($override === UserPermissionOverride::EFFECT_DENY) {
            return false;
        }
        if ($override === UserPermissionOverride::EFFECT_ALLOW) {
            return true;
        }

        $membership = $this->memberships()
            ->where('company_id', $companyId)
            ->first();

        if (! $membership || $membership->role_id === null) {
            return false;
        }

        return Role::query()
            ->whereKey($membership->role_id)
            ->whereHas('permissions', function ($query) use ($permission) {
                $query->where('name', $permission);
            })
            ->exists();
    }

    /**
     * Phase Գ.6 / Bucket D.4 — effective permission names for one company:
     * (role grants ∪ allow-overrides) − deny-overrides. Used to project the
     * frontend `permissions` array and by the CheckPermission middleware.
     *
     * @return list<string>
     */
    public function effectivePermissionsForCompany(int $companyId): array
    {
        $membership = $this->memberships()
            ->where('company_id', $companyId)
            ->with('role.permissions')
            ->first();

        $rolePerms = ($membership && $membership->role)
            ? $membership->role->permissions->pluck('name')->all()
            : [];

        $overrides = $this->permissionOverrides()
            ->where('company_id', $companyId)
            ->with('permission')
            ->get();

        $allow = $overrides->where('effect', UserPermissionOverride::EFFECT_ALLOW)
            ->map(fn (UserPermissionOverride $o) => $o->permission?->name)
            ->filter()
            ->all();
        $deny = $overrides->where('effect', UserPermissionOverride::EFFECT_DENY)
            ->map(fn (UserPermissionOverride $o) => $o->permission?->name)
            ->filter()
            ->all();

        $effective = array_values(array_diff(
            array_unique(array_merge($rolePerms, $allow)),
            $deny
        ));
        sort($effective);

        return $effective;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(UserCompany::class);
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    /** Phase 7.1 — orders placed by this user (as B2C customer). */
    public function bookings(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function savedItems(): HasMany
    {
        return $this->hasMany(SavedItem::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    // ─── Phase 1 / Step B.1 — is_super_admin computed accessor ──────────
    //
    // Background: `is_super_admin` was referenced across the codebase as
    // if it were a real DB column (`$user->is_super_admin`,
    // `User::where('is_super_admin', true)`). The column never existed.
    // Eloquent magic returned NULL for property reads (silently falsy),
    // and any SQL WHERE/SELECT clause errored at runtime. See Phase 1
    // audit doc §6 for the inventory of call sites.
    //
    // Definition: a user is super-admin if they hold a role named
    // `super_admin` with scope=`platform` via any of their company
    // memberships (or via a future direct user-role pivot if added).
    //
    // For SQL contexts (controllers that ran WHERE/SELECT), use the
    // scopeSuperAdmins() query scope below.

    /**
     * Computed: true if this user holds the platform-scoped super_admin
     * role via any company membership.
     *
     * Caller can also write `$user->is_super_admin = true;` in test
     * fixtures — the attribute setter writes to the in-memory attribute
     * bag, mirroring the legacy "dynamic attr" pattern used in existing
     * feature tests (see tests/Feature/AdminBulkNotificationTest.php).
     */
    protected function isSuperAdmin(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value, array $attributes): bool {
                // Test fixtures may set the dynamic attr explicitly.
                if (array_key_exists('is_super_admin', $attributes)) {
                    return (bool) $attributes['is_super_admin'];
                }

                // Otherwise compute from role memberships.
                if (! $this->exists) {
                    return false;
                }

                return $this->memberships()
                    ->whereHas('role', function (Builder $query): void {
                        $query->where('name', 'super_admin')
                            ->where('scope', Role::SCOPE_PLATFORM);
                    })
                    ->exists();
            },
            set: fn (mixed $value): array => ['is_super_admin' => (bool) $value],
        );
    }

    /**
     * Query scope replacing the broken `where('is_super_admin', true)`
     * SQL clauses (audit doc §6). Returns users who hold the platform-
     * scoped super_admin role via any company membership.
     */
    public function scopeSuperAdmins(Builder $query): Builder
    {
        return $query->whereHas('memberships.role', function (Builder $roleQuery): void {
            $roleQuery->where('name', 'super_admin')
                ->where('scope', Role::SCOPE_PLATFORM);
        });
    }
}

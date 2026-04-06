<?php

namespace App\Services\Admin;

use App\Models\Company;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AdminAccessService
{
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_PLATFORM_ADMIN = 'platform_admin';

    public const ROLE_OPERATOR_ADMIN = 'operator_admin';

    /** @var array<string, list<string>> */
    private const ROLE_ALIASES = [
        self::ROLE_SUPER_ADMIN => ['zulu_super_admin'],
        self::ROLE_PLATFORM_ADMIN => ['zulu_platform_admin'],
        self::ROLE_OPERATOR_ADMIN => ['company_admin', 'company_operator', 'admin'],
    ];

    /** @var list<string> */
    private const SUPER_ADMIN_ROLE_NAMES = [
        self::ROLE_SUPER_ADMIN,
        ...self::ROLE_ALIASES[self::ROLE_SUPER_ADMIN],
    ];

    /** @var list<string> */
    private const PLATFORM_ADMIN_ROLE_NAMES = [
        self::ROLE_PLATFORM_ADMIN,
        ...self::ROLE_ALIASES[self::ROLE_PLATFORM_ADMIN],
    ];

    /** @var list<string> */
    private const OPERATOR_ADMIN_ROLE_NAMES = [
        self::ROLE_OPERATOR_ADMIN,
        ...self::ROLE_ALIASES[self::ROLE_OPERATOR_ADMIN],
    ];

    /** @var list<string> */
    private const OPERATOR_ADMIN_PERMISSION_NAMES = ['company.admin', 'company.manage', 'company.users.manage'];

    /** @var list<string> */
    private const SUPER_ADMIN_PERMISSION_NAMES = ['platform.admin', 'platform.manage', 'super_admin'];

    /** @var list<string> */
    private const PLATFORM_ADMIN_PERMISSION_NAMES = [
        'platform.companies.list',
        'platform.companies.governance',
        'platform.approvals.list',
        'platform.approvals.manage',
        'platform.orders.list',
        'platform.payments.list',
        'platform.packages.moderate',
        'platform.stats.view',
        'platform.settings.manage',
        'platform.users.list',
        'platform.inventory.view',
        'platform.finance.view',
    ];

    /**
     * Request-level in-memory cache to avoid repeated DB hits within a single request.
     * Keys: "super_{id}", "platform_{id}", "perms_{id}", "companies_all", "company_ids_{id}_{perm}"
     *
     * @var array<string, mixed>
     */
    private array $cache = [];

    public function isSuperAdmin(User $user): bool
    {
        $key = 'super_'.$user->id;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $cacheKey = 'admin_is_super_'.$user->id;
        $result = Cache::remember($cacheKey, 300, function () use ($user): bool {
            return $user->memberships()
                ->where(function (Builder $query): void {
                    $query->whereHas('role', function (Builder $roleQuery): void {
                        $roleQuery->whereIn('name', self::SUPER_ADMIN_ROLE_NAMES);
                    })->orWhereHas('role.permissions', function (Builder $permQuery): void {
                        $permQuery->whereIn('name', self::SUPER_ADMIN_PERMISSION_NAMES);
                    });
                })
                ->exists();
        });

        return $this->cache[$key] = $result;
    }

    public function canonicalRoleForUser(User $user): string
    {
        if ($this->isSuperAdmin($user)) {
            return self::ROLE_SUPER_ADMIN;
        }

        if ($this->isPlatformAdmin($user)) {
            return self::ROLE_PLATFORM_ADMIN;
        }

        return self::ROLE_OPERATOR_ADMIN;
    }

    public function canonicalizeRoleName(string $roleName): string
    {
        $normalized = strtolower(trim($roleName));
        if ($normalized === self::ROLE_SUPER_ADMIN || in_array($normalized, self::ROLE_ALIASES[self::ROLE_SUPER_ADMIN], true)) {
            return self::ROLE_SUPER_ADMIN;
        }

        if ($normalized === self::ROLE_PLATFORM_ADMIN || in_array($normalized, self::ROLE_ALIASES[self::ROLE_PLATFORM_ADMIN], true)) {
            return self::ROLE_PLATFORM_ADMIN;
        }

        if ($normalized === self::ROLE_OPERATOR_ADMIN || in_array($normalized, self::ROLE_ALIASES[self::ROLE_OPERATOR_ADMIN], true)) {
            return self::ROLE_OPERATOR_ADMIN;
        }

        return $normalized;
    }

    public function isPlatformAdmin(User $user): bool
    {
        $key = 'platform_'.$user->id;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if ($this->isSuperAdmin($user)) {
            return $this->cache[$key] = true;
        }

        $cacheKey = 'admin_is_platform_'.$user->id;
        $result = Cache::remember($cacheKey, 300, function () use ($user): bool {
            return $user->memberships()
                ->whereHas('role', function (Builder $query): void {
                    $query->whereIn('name', self::PLATFORM_ADMIN_ROLE_NAMES);
                })
                ->exists()
                || $this->hasAnyPermission($user, self::PLATFORM_ADMIN_PERMISSION_NAMES)
                || $this->hasPermissionPrefix($user, 'platform.');
        });

        return $this->cache[$key] = $result;
    }

    public function isOperatorAdmin(User $user, ?int $companyId = null): bool
    {
        $query = $user->memberships()
            ->whereHas('role', function (Builder $roleQuery): void {
                $roleQuery->whereIn('name', self::OPERATOR_ADMIN_ROLE_NAMES);
            });

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        if ($query->exists()) {
            return true;
        }

        if ($companyId !== null) {
            return $this->hasOperatorAdminPermissionInCompany($user, $companyId);
        }

        return false;
    }

    /**
     * Cross-tenant operator statistics: optional ?company_id= on operator statistics APIs.
     * True for full super-admins and for non-super users who hold platform.stats.view only.
     */
    public function isAdminStatisticsSuperScope(User $user): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $this->hasOperatorStatisticsViewPermission($user);
    }

    /**
     * Statistics-wide scope without full platform super-admin (platform.stats.view, etc.).
     */
    public function isStatisticsElevatedOnly(User $user): bool
    {
        return $this->isAdminStatisticsSuperScope($user) && ! $this->isSuperAdmin($user);
    }

    private function hasOperatorStatisticsViewPermission(User $user): bool
    {
        $permissionNames = $this->permissionNames($user);

        return in_array('platform.stats.view', $permissionNames, true);
    }

    /**
     * Company context for operator statistics: super scope uses company_id query;
     * otherwise first membership with a role (aligned with admin session admin_company_id).
     */
    public function resolveOperatorStatisticsCompanyId(Request $request, User $user): ?int
    {
        if ($this->isAdminStatisticsSuperScope($user)) {
            $companyId = $request->query('company_id');

            return is_numeric($companyId) ? (int) $companyId : null;
        }

        return $this->resolveFirstOperatorAdminCompanyId($user);
    }

    /**
     * Operator commerce APIs: super admin has platform-wide access to any tenant company
     * without user_company membership; other users remain tenant + permission scoped.
     */
    public function allowsCommerceOperatorAccess(User $user, int $companyId, string $permission): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // Platform staff (platform_admin role / platform.* perms) manage inventory across tenants.
        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        return $user->belongsToCompany($companyId)
            && $user->hasCompanyPermission($companyId, $permission);
    }

    public function canAccessCompanyScopedResource(User $user, int $companyId, ?string $permission = null): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $user->belongsToCompany($companyId)) {
            return false;
        }

        if ($permission === null) {
            return true;
        }

        return $user->hasCompanyPermission($companyId, $permission);
    }

    public function resolveFirstOperatorAdminCompanyId(User $user): ?int
    {
        $memberships = UserCompany::query()
            ->where('user_id', $user->id)
            ->whereNotNull('role_id')
            ->with('role.permissions')
            ->orderBy('id')
            ->get();

        foreach ($memberships as $membership) {
            if ($membership->role === null) {
                continue;
            }

            if (in_array($membership->role->name, self::OPERATOR_ADMIN_ROLE_NAMES, true)) {
                return (int) $membership->company_id;
            }

            $permissionNames = $membership->role->permissions->pluck('name')->all();
            if (count(array_intersect($permissionNames, self::OPERATOR_ADMIN_PERMISSION_NAMES)) > 0) {
                return (int) $membership->company_id;
            }
        }

        return null;
    }

    /**
     * Company ids for commerce list endpoints (matches company list super-admin scope: all tenants).
     * Cached per-user per-permission within the request lifetime.
     *
     * @return list<int>
     */
    public function companyIdsForCommerceList(User $user, string $viewPermission): array
    {
        $key = 'company_ids_'.$user->id.'_'.$viewPermission;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if ($this->isSuperAdmin($user)) {
            return $this->cache[$key] = $this->allCompanyIdsOrdered();
        }

        // Platform roles (platform_admin + platform.* permissions) are not tenant-scoped; without this,
        // `offers.view` resolves to zero companies and operator UIs cannot load car/hotel/etc. offers.
        if ($this->isPlatformAdmin($user)) {
            return $this->cache[$key] = $this->allCompanyIdsOrdered();
        }

        $result = UserCompany::query()
            ->where('user_id', $user->id)
            ->whereNotNull('role_id')
            ->whereHas('role.permissions', function (Builder $q) use ($viewPermission): void {
                $q->where('name', $viewPermission);
            })
            ->orderBy('company_id')
            ->pluck('company_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return $this->cache[$key] = $result;
    }

    /**
     * All company IDs ordered by id. Cached within the request.
     *
     * @return list<int>
     */
    public function allCompanyIdsOrdered(): array
    {
        if (array_key_exists('companies_all', $this->cache)) {
            return $this->cache['companies_all'];
        }

        return $this->cache['companies_all'] = Cache::remember(
            'admin_all_company_ids',
            300, // 5 minutes
            fn () => Company::query()
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );
    }

    private function hasAnyPermission(User $user, array $permissionNames): bool
    {
        return count(array_intersect($this->permissionNames($user), $permissionNames)) > 0;
    }

    private function hasOperatorAdminPermissionInCompany(User $user, int $companyId): bool
    {
        foreach (self::OPERATOR_ADMIN_PERMISSION_NAMES as $permissionName) {
            if ($user->hasCompanyPermission($companyId, $permissionName)) {
                return true;
            }
        }

        return false;
    }

    private function hasPermissionPrefix(User $user, string $prefix): bool
    {
        foreach ($this->permissionNames($user) as $permissionName) {
            if (str_starts_with($permissionName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * All permission names for the user across all company memberships.
     * Cached within the request.
     *
     * @return list<string>
     */
    private function permissionNames(User $user): array
    {
        $key = 'perms_'.$user->id;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $memberships = UserCompany::query()
            ->where('user_id', $user->id)
            ->whereNotNull('role_id')
            ->with('role.permissions')
            ->get();

        $result = $memberships
            ->map(fn ($m) => $m->role)
            ->filter()
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();

        return $this->cache[$key] = $result;
    }
}

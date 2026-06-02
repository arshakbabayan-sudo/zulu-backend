<?php

namespace App\Services\Admin;

use App\Models\Company;
use App\Models\PlatformStaffScope;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $loadedMemberships = $this->loadedMemberships($user);
        if ($loadedMemberships !== null) {
            $result = $this->isSuperAdminFromLoadedMemberships($loadedMemberships);

            return $this->cache[$key] = $result;
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

        // Pure agent (no operator-tier role) — display as agent so the UI
        // doesn't mislabel them as operator_admin.
        $roleNames = $user->memberships
            ->map(fn ($m) => $m->role?->name)
            ->filter()
            ->all();
        if ($roleNames !== [] && ! array_intersect($roleNames, [self::ROLE_OPERATOR_ADMIN, 'company_admin', 'company_operator'])) {
            if (in_array('agent', $roleNames, true)) {
                return 'agent';
            }
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

        $loadedMemberships = $this->loadedMemberships($user);
        if ($loadedMemberships !== null) {
            foreach ($loadedMemberships as $membership) {
                $role = $membership->role;
                if ($role !== null && in_array($role->name, self::PLATFORM_ADMIN_ROLE_NAMES, true)) {
                    return $this->cache[$key] = true;
                }
            }

            $result = $this->hasAnyPermission($user, self::PLATFORM_ADMIN_PERMISSION_NAMES)
                || $this->hasPermissionPrefix($user, 'platform.');

            return $this->cache[$key] = $result;
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
     * Admin-panel access gate for the FUTURE operator/agent rollout — built in
     * Phase 2 but DELIBERATELY NOT yet wired into EnsurePlatformAdmin (the
     * middleware still uses isPlatformAdmin, so operators are 403'd for now).
     *
     * Returns true for anyone with a legitimate admin-panel seat:
     *   - super_admin / platform_admin (the existing isPlatformAdmin path), AND
     *   - operator_admin / company_admin / agent — anyone with a role-bound
     *     UserCompany membership.
     * A plain B2C customer / unverified signup (no role-bound membership) is
     * false — admin routes are never for them.
     *
     * Why it's not wired yet (Phase 2 finding, 2026-06-02): the R.1 role
     * hygiene migration (2026-05-28) stripped `platform.*` perms from
     * operator/agent roles, so isPlatformAdmin() is false for them and every
     * /platform-admin request 403s at the middleware. Simply swapping the
     * middleware to this method makes operators reach EVERY route in the
     * group — including write / route-model-bound methods that lack per-row
     * tenant ownership (e.g. CrmController::updateDeal/destroyDeal operate on
     * any deal id; voucher/contract show by id). Widening access before those
     * are audited would expose cross-tenant writes. So this is the documented
     * Phase 6 swap-in point, to be wired together with the Phase 3 per-row
     * ownership work + a per-route allowlist — not before.
     */
    public function canAccessAdminPanel(User $user): bool
    {
        $key = 'admin_panel_'.$user->id;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if ($this->isPlatformAdmin($user)) {
            return $this->cache[$key] = true;
        }

        $loadedMemberships = $this->loadedMemberships($user);
        if ($loadedMemberships !== null) {
            foreach ($loadedMemberships as $membership) {
                if ($membership->role_id !== null) {
                    return $this->cache[$key] = true;
                }
            }

            return $this->cache[$key] = false;
        }

        return $this->cache[$key] = UserCompany::query()
            ->where('user_id', $user->id)
            ->whereNotNull('role_id')
            ->exists();
    }

    /**
     * Genuine ZULU platform staff — distinguishes a real platform_admin
     * (assigned the platform_admin role on its membership) from an operator
     * that merely carries a `platform.*` permission via its operator_admin /
     * company_admin role. Both make isPlatformAdmin()=true (it gates menu /
     * page access), but only the former is platform STAFF for tenant-scope
     * decisions — operators must stay scoped to their own company.
     *
     * Phase-2 helper (blueprint §3.4). Used by visibleCompanyIds() today and
     * will resolve Phase 4 staff assignments (country / company) once
     * platform_staff_scopes lands.
     */
    public function isPlatformStaff(User $user): bool
    {
        $key = 'platform_staff_'.$user->id;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if ($this->isSuperAdmin($user)) {
            return $this->cache[$key] = true;
        }

        $loadedMemberships = $this->loadedMemberships($user);
        if ($loadedMemberships !== null) {
            foreach ($loadedMemberships as $membership) {
                $role = $membership->role;
                if ($role !== null && in_array($role->name, self::PLATFORM_ADMIN_ROLE_NAMES, true)) {
                    return $this->cache[$key] = true;
                }
            }

            return $this->cache[$key] = false;
        }

        $cacheKey = 'admin_is_platform_staff_'.$user->id;
        $result = Cache::remember($cacheKey, 300, function () use ($user): bool {
            return $user->memberships()
                ->whereHas('role', function (Builder $query): void {
                    $query->whereIn('name', self::PLATFORM_ADMIN_ROLE_NAMES);
                })
                ->exists();
        });

        return $this->cache[$key] = $result;
    }

    /**
     * Unified tenant-scope resolver — single source of truth for "which
     * companies' rows may this NON-SUPER caller see across the admin panel"
     * (Layer A of the RBAC blueprint scope model). Call sites must still gate
     * with isSuperAdmin() upstream; super-admins have unrestricted access and
     * should not pass through scoping at all. Empty list = caller owns no
     * company → downstream services translate to `1 = 0` (zero rows, never a
     * leak).
     *
     * Resolution:
     *  - Platform staff (isPlatformStaff) → assignedCompanyIds (Phase 4):
     *      the companies a super-admin assigned them (direct or by country).
     *      Fail-closed — no assignment = [] = zero rows, never ALL.
     *  - Operator/agent + their employees → callerCompanyIds (own
     *      UserCompany memberships, via Layer A of the blueprint).
     *
     * Defensive fall-through for a super-admin call site returns
     * allCompanyIdsOrdered (matches the blueprint's "super → ALL").
     *
     * @return list<int>
     */
    public function visibleCompanyIds(User $user): array
    {
        if ($this->isSuperAdmin($user)) {
            return $this->allCompanyIdsOrdered();
        }

        // Platform staff (genuine ZULU platform_admin) see ONLY the companies
        // a super-admin assigned them (Phase 4). Fail-closed: no assignment =
        // [] = zero rows. Operators/agents fall through to their own
        // memberships.
        if ($this->isPlatformStaff($user)) {
            return $this->assignedCompanyIds($user);
        }

        return $this->callerCompanyIds($user);
    }

    /**
     * Company ids a platform-staff user (platform_admin) has been ASSIGNED by a
     * super-admin (RBAC blueprint Phase 4). Resolved from platform_staff_scopes:
     *   - direct rows (company_id set) → that company
     *   - country rows (country set)   → every company in that country
     *     (companies.country is free-text + inconsistent in prod, so the match
     *     is lower/trim and best-effort; direct company_id is the precise path)
     *
     * Returns [] when the staffer has no assignments → they see nothing until a
     * super-admin assigns them. Cached within the request.
     *
     * @return list<int>
     */
    public function assignedCompanyIds(User $user): array
    {
        $key = 'assigned_company_ids_'.$user->id;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $scopes = PlatformStaffScope::query()
            ->where('user_id', $user->id)
            ->get(['company_id', 'country']);

        if ($scopes->isEmpty()) {
            return $this->cache[$key] = [];
        }

        $directIds = $scopes
            ->pluck('company_id')
            ->filter(static fn ($id) => $id !== null)
            ->map(static fn ($id) => (int) $id);

        $countries = $scopes
            ->pluck('country')
            ->filter(static fn ($c) => is_string($c) && trim($c) !== '')
            ->map(static fn (string $c) => mb_strtolower(trim($c)))
            ->unique()
            ->values();

        $countryIds = collect();
        if ($countries->isNotEmpty()) {
            $countryIds = Company::query()
                ->where(function (Builder $q) use ($countries): void {
                    foreach ($countries as $c) {
                        $q->orWhereRaw('lower(trim(country)) = ?', [$c]);
                    }
                })
                ->pluck('id')
                ->map(static fn ($id) => (int) $id);
        }

        return $this->cache[$key] = $directIds
            ->merge($countryIds)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Company-owner roles for Layer-B row scoping. The company creator gets
     * `company_admin` on application approval (rank 4, 2026-05-30 fix); they
     * own all of their company's data. operator_admin / company_operator /
     * agent are EMPLOYEES under that owner and default to their own rows.
     *
     * @var list<string>
     */
    private const COMPANY_OWNER_ROLE_NAMES = ['company_admin'];

    /**
     * True when the caller owns their company (sees ALL of its rows by right),
     * as opposed to being an employee within it. Owner = holds the
     * `company_admin` role on at least one membership.
     */
    public function isCompanyOwner(User $user): bool
    {
        $key = 'company_owner_'.$user->id;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = in_array(
            true,
            array_map(
                static fn (string $name): bool => in_array($name, self::COMPANY_OWNER_ROLE_NAMES, true),
                $this->roleNames($user),
            ),
            true,
        );
    }

    /**
     * Within-company row scope (blueprint Layer B). Given the caller and the
     * module's "see whole company" permission (e.g. `bookings.view_all`),
     * returns either:
     *   - null → NO row filter: the caller may see the whole company's rows
     *            (they are the company owner, OR they hold <module>.view_all —
     *            a senior manager the owner promoted via the permission drawer).
     *   - int  → filter the entity's attribution column to THIS user id: a
     *            plain employee sees only their own rows for this module.
     *
     * Super-admins and platform staff never reach this (their call sites gate
     * upstream and skip row scoping). The COMPANY layer (visibleCompanyIds) is
     * applied first and independently; this only narrows WITHIN those
     * companies. Entities without an attribution column can't use this — they
     * stay company-scoped only (callers simply don't invoke it for those).
     */
    public function employeeRowScopeUserId(User $user, string $viewAllPermission): ?int
    {
        if ($this->isCompanyOwner($user)) {
            return null;
        }

        if (in_array($viewAllPermission, $this->permissionNames($user), true)) {
            return null;
        }

        return (int) $user->id;
    }

    /**
     * Distinct role names across the caller's role-bound memberships.
     *
     * @return list<string>
     */
    private function roleNames(User $user): array
    {
        $key = 'role_names_'.$user->id;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $loadedMemberships = $this->loadedMemberships($user);
        if ($loadedMemberships !== null) {
            $names = $loadedMemberships
                ->map(fn (UserCompany $m) => $m->role?->name)
                ->filter()
                ->unique()
                ->values()
                ->all();

            return $this->cache[$key] = $names;
        }

        return $this->cache[$key] = UserCompany::query()
            ->where('user_id', $user->id)
            ->whereNotNull('role_id')
            ->with('role:id,name')
            ->get()
            ->map(fn (UserCompany $m) => $m->role?->name)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Company IDs the caller belongs to via a role-bound membership.
     *
     * Used to tenant-scope platform-admin list endpoints (bookings, etc.) so a
     * non-super operator/agent only ever sees their OWN company's rows — even
     * if their role carries a `platform.*` permission that makes
     * isPlatformAdmin() true (that flag governs menu/page access, NOT which
     * tenant's data they may read). Returns [] when the user owns no company,
     * in which case the caller should see nothing.
     *
     * Prefer visibleCompanyIds() at controller call sites — it routes
     * platform staff through the Phase-4 assignment lookup when that lands.
     *
     * @return list<int>
     */
    public function callerCompanyIds(User $user): array
    {
        $loaded = $this->loadedMemberships($user);
        if ($loaded !== null) {
            return collect($loaded)
                ->filter(fn ($m) => $m->role_id !== null)
                ->map(fn ($m) => (int) $m->company_id)
                ->unique()
                ->values()
                ->all();
        }

        return UserCompany::query()
            ->where('user_id', $user->id)
            ->whereNotNull('role_id')
            ->pluck('company_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
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
        $memberships = $this->loadedMemberships($user);
        if ($memberships === null) {
            $memberships = UserCompany::query()
                ->where('user_id', $user->id)
                ->whereNotNull('role_id')
                ->with('role.permissions')
                ->orderBy('id')
                ->get();
        } else {
            $memberships = $memberships
                ->whereNotNull('role_id')
                ->sortBy('id')
                ->values();
        }

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

        $loadedMemberships = $this->loadedMemberships($user);
        if ($loadedMemberships !== null) {
            $result = $loadedMemberships
                ->map(fn (UserCompany $m) => $m->role)
                ->filter()
                ->flatMap(fn ($role) => $role->permissions->pluck('name'))
                ->unique()
                ->values()
                ->all();

            return $this->cache[$key] = $result;
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

    /**
     * @return Collection<int, UserCompany>|null
     */
    private function loadedMemberships(User $user): ?Collection
    {
        if (! $user->relationLoaded('memberships')) {
            return null;
        }

        /** @var Collection<int, UserCompany> $memberships */
        $memberships = $user->getRelation('memberships');
        $memberships->loadMissing('role.permissions');

        return $memberships;
    }

    /**
     * @param  Collection<int, UserCompany>  $memberships
     */
    private function isSuperAdminFromLoadedMemberships(Collection $memberships): bool
    {
        foreach ($memberships as $membership) {
            $role = $membership->role;
            if ($role === null) {
                continue;
            }

            if (in_array($role->name, self::SUPER_ADMIN_ROLE_NAMES, true)) {
                return true;
            }

            $permissionNames = $role->permissions->pluck('name')->all();
            if (count(array_intersect($permissionNames, self::SUPER_ADMIN_PERMISSION_NAMES)) > 0) {
                return true;
            }
        }

        return false;
    }
}

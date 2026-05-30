<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserCompany;
use App\Models\UserPermissionOverride;
use App\Services\Admin\AdminAccessService;
use App\Services\Admin\CompanyAccessService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase Գ.6 / Bucket D.4 — tenant per-employee permission management.
 *
 * Lets an operator/agent manager (or super-admin) toggle individual action
 * permissions per employee within their own company, via checkboxes. Model:
 * the role gives the baseline; this controller writes allow/deny overrides
 * (see UserPermissionOverride). Two hard limits enforced server-side:
 *   1. Ceiling — a manager can only grant permissions they themselves hold
 *      (super-admins get all tenant-assignable permissions).
 *   2. Rank — a manager can only edit employees of equal-or-lower role rank,
 *      and never their own permissions (unless super-admin).
 * Platform-scoped and escalation permissions are never tenant-assignable.
 */
class CompanyRbacController extends Controller
{
    /** Role privilege rank — mirrors CompanyController::ROLE_RANK. */
    private const ROLE_RANK = [
        'company_admin' => 4,
        'admin' => 4,
        'operator_admin' => 3,
        'company_operator' => 2,
        'company_viewer' => 1,
    ];

    /** Permissions that can never be granted through the tenant checkbox UI. */
    private const NON_ASSIGNABLE_NAMES = [
        'super_admin',
        'platform.admin',
        'platform.manage',
        'company.admin',
        'company.manage',
    ];

    public function __construct(
        private AdminAccessService $adminAccessService,
        private CompanyAccessService $companyAccessService,
    ) {}

    public function show(Request $request, Company $company, User $user): JsonResponse
    {
        $caller = $request->user();
        if ($deny = $this->authorizeManage($caller, $company, $user)) {
            return $deny;
        }

        $companyId = (int) $company->id;
        $assignable = $this->assignablePermissions($caller, $companyId);
        $effective = $user->effectivePermissionsForCompany($companyId);
        $roleSet = $this->roleBaselineFor($user, $companyId);
        $overrideMap = $this->overrideMapFor($user, $companyId);

        $rows = $assignable->map(function (Permission $p) use ($roleSet, $overrideMap, $effective): array {
            [$module, $action] = $this->splitName($p->name);

            return [
                'permission_id' => $p->id,
                'name' => $p->name,
                'module' => $module,
                'action' => $action,
                'granted' => in_array($p->name, $effective, true),
                'from_role' => in_array($p->name, $roleSet, true),
                'override' => $overrideMap[$p->name] ?? null, // 'allow' | 'deny' | null
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_name' => $this->roleNameFor($user, $companyId),
                    // 2FA hierarchy (2026-05-31) — surfaced here so the
                    // Permissions drawer can render the "Two-factor
                    // authentication" toggle without a second round-trip.
                    'two_factor_required' => (bool) $user->two_factor_required,
                    'two_factor_method' => $user->two_factor_method,
                ],
                'company_id' => $companyId,
                'can_edit' => true,
                'permissions' => $rows,
            ],
        ]);
    }

    /**
     * PUT /api/companies/{company}/users/{user}/2fa-policy
     *
     * 2FA hierarchy (Arshak 2026-05-31): the operator/agent manager decides
     * per-employee whether 2FA at login is mandatory. The user themselves
     * still picks the channel (TOTP vs Email) via /account/2fa/method.
     *
     * Uses the same authorize-manage gate as the permissions endpoints —
     * caller must be able to manage this company AND outrank the target.
     */
    public function setTwoFactorPolicy(Request $request, Company $company, User $user): JsonResponse
    {
        $caller = $request->user();
        if ($deny = $this->authorizeManage($caller, $company, $user)) {
            return $deny;
        }

        $validated = $request->validate([
            'required' => ['required', 'boolean'],
        ]);

        $user->forceFill(['two_factor_required' => (bool) $validated['required']])->saveQuietly();

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $user->id,
                'two_factor_required' => (bool) $validated['required'],
            ],
        ]);
    }

    public function sync(Request $request, Company $company, User $user): JsonResponse
    {
        $caller = $request->user();
        if ($deny = $this->authorizeManage($caller, $company, $user)) {
            return $deny;
        }

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*.permission_id' => ['required', 'integer', 'exists:permissions,id'],
            'permissions.*.granted' => ['required', 'boolean'],
        ]);

        $companyId = (int) $company->id;
        $assignableById = $this->assignablePermissions($caller, $companyId)->keyBy('id');
        $roleSet = $this->roleBaselineFor($user, $companyId);

        // Ceiling — reject the whole request if any permission is outside what
        // the caller may grant. Fail closed rather than silently dropping.
        foreach ($validated['permissions'] as $row) {
            if (! $assignableById->has((int) $row['permission_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot assign one or more of these permissions.',
                    'errors' => ['permissions' => ['A permission is outside your privilege ceiling.']],
                ], 403);
            }
        }

        DB::transaction(function () use ($validated, $assignableById, $roleSet, $user, $companyId, $caller): void {
            foreach ($validated['permissions'] as $row) {
                $permId = (int) $row['permission_id'];
                $granted = (bool) $row['granted'];
                $name = $assignableById->get($permId)->name;
                $roleHas = in_array($name, $roleSet, true);

                if ($granted === $roleHas) {
                    // Desired state already matches the role baseline → drop
                    // any override so we don't accumulate redundant rows.
                    UserPermissionOverride::query()
                        ->where('user_id', $user->id)
                        ->where('company_id', $companyId)
                        ->where('permission_id', $permId)
                        ->delete();

                    continue;
                }

                $effect = $granted
                    ? UserPermissionOverride::EFFECT_ALLOW
                    : UserPermissionOverride::EFFECT_DENY;

                UserPermissionOverride::query()->updateOrCreate(
                    ['user_id' => $user->id, 'company_id' => $companyId, 'permission_id' => $permId],
                    ['effect' => $effect, 'granted_by_user_id' => $caller?->id],
                );
            }
        });

        return $this->show($request, $company, $user);
    }

    private function authorizeManage(?User $caller, Company $company, User $target): ?JsonResponse
    {
        if ($caller === null || ! $this->companyAccessService->canManageCompany($caller, $company)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $companyId = (int) $company->id;

        $belongs = UserCompany::query()
            ->where('company_id', $companyId)
            ->where('user_id', $target->id)
            ->exists();
        if (! $belongs) {
            return response()->json(['success' => false, 'message' => 'Employee not found in company'], 404);
        }

        if ($this->adminAccessService->isSuperAdmin($caller)) {
            return null;
        }

        if ((int) $caller->id === (int) $target->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot edit your own permissions.',
            ], 403);
        }

        $callerRank = self::ROLE_RANK[$this->roleNameFor($caller, $companyId) ?? ''] ?? 0;
        $targetRank = self::ROLE_RANK[$this->roleNameFor($target, $companyId) ?? ''] ?? 0;
        if ($targetRank > $callerRank) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot manage an employee ranked above you.',
            ], 403);
        }

        return null;
    }

    /**
     * Permissions the caller may toggle for an employee: the company-scoped
     * catalog (no platform/escalation perms), intersected with the caller's
     * own held permissions unless they are a super-admin.
     *
     * @return Collection<int, Permission>
     */
    private function assignablePermissions(User $caller, int $companyId): Collection
    {
        /** @var Collection<int, Permission> $all */
        $all = Permission::query()->orderBy('name')->get();

        $filtered = $all->filter(function (Permission $p): bool {
            if (in_array($p->name, self::NON_ASSIGNABLE_NAMES, true)) {
                return false;
            }

            return ! str_starts_with($p->name, 'platform.');
        });

        if ($this->adminAccessService->isSuperAdmin($caller)) {
            return $filtered->values();
        }

        $callerPerms = $caller->effectivePermissionsForCompany($companyId);

        return $filtered
            ->filter(fn (Permission $p) => in_array($p->name, $callerPerms, true))
            ->values();
    }

    /** @return list<string> */
    private function roleBaselineFor(User $user, int $companyId): array
    {
        $membership = UserCompany::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->with('role.permissions')
            ->first();

        if (! $membership || ! $membership->role) {
            return [];
        }

        return $membership->role->permissions->pluck('name')->all();
    }

    /** @return array<string, string> permission name => effect */
    private function overrideMapFor(User $user, int $companyId): array
    {
        $map = [];
        $rows = UserPermissionOverride::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->with('permission')
            ->get();

        foreach ($rows as $row) {
            $name = $row->permission?->name;
            if ($name !== null) {
                $map[$name] = $row->effect;
            }
        }

        return $map;
    }

    private function roleNameFor(User $user, int $companyId): ?string
    {
        return UserCompany::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->join('roles', 'user_company.role_id', '=', 'roles.id')
            ->orderByDesc('roles.id')
            ->value('roles.name');
    }

    /** @return array{0: string, 1: string} [module, action] */
    private function splitName(string $name): array
    {
        $pos = strrpos($name, '.');
        if ($pos === false) {
            return [$name, ''];
        }

        return [substr($name, 0, $pos), substr($name, $pos + 1)];
    }
}

<?php

namespace App\Http\Resources\Api;

use App\Models\UserPermissionOverride;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $user->loadMissing('memberships.role.permissions', 'companies.sellerPermissions', 'companies.modulePermissions', 'permissionOverrides.permission');

        $adminAccess = app(AdminAccessService::class);
        $isSuperAdmin = $adminAccess->isSuperAdmin($user);
        $isPlatformAdmin = $adminAccess->isPlatformAdmin($user);
        $operatorStatisticsPlatformScope = $adminAccess->isAdminStatisticsSuperScope($user);
        $isStatisticsElevatedOnly = $adminAccess->isStatisticsElevatedOnly($user);

        // Role permissions grouped by company, so per-employee overrides
        // (Phase Գ.6 / Bucket D.4) can be applied per company before the
        // effective sets are unioned for the coarse frontend gate.
        $roleNames = [];
        $rolePermsByCompany = [];
        foreach ($user->memberships as $membership) {
            $role = $membership->role;
            if ($role === null) {
                continue;
            }
            $roleNames[] = $role->name;
            $companyId = (int) $membership->company_id;
            foreach ($role->permissions as $permission) {
                $rolePermsByCompany[$companyId][$permission->name] = true;
            }
        }

        // Override rows grouped by company → effect → permission names.
        $overridesByCompany = [];
        foreach ($user->permissionOverrides as $override) {
            $name = $override->permission?->name;
            if ($name === null) {
                continue;
            }
            $overridesByCompany[(int) $override->company_id][$override->effect][] = $name;
        }

        // Effective per company = (role ∪ allow) − deny; union across companies.
        $permissionKeys = [];
        $allCompanyIds = array_unique(array_merge(
            array_keys($rolePermsByCompany),
            array_keys($overridesByCompany)
        ));
        foreach ($allCompanyIds as $companyId) {
            $roleSet = array_keys($rolePermsByCompany[$companyId] ?? []);
            $allow = $overridesByCompany[$companyId][UserPermissionOverride::EFFECT_ALLOW] ?? [];
            $deny = $overridesByCompany[$companyId][UserPermissionOverride::EFFECT_DENY] ?? [];
            $effective = array_diff(array_unique(array_merge($roleSet, $allow)), $deny);
            foreach ($effective as $name) {
                $permissionKeys[$name] = true;
            }
        }

        $roleNames = array_values(array_unique($roleNames));
        sort($roleNames);

        $sortedCompanies = $user->companies->sortBy('id')->values();
        $activeCompanyId = $sortedCompanies->isEmpty()
            ? null
            : (int) $sortedCompanies->first()->id;

        $sellerServiceTypesByCompany = [];
        foreach ($sortedCompanies as $company) {
            $types = $company->sellerPermissions
                ->where('status', 'active')
                ->pluck('service_type')
                ->unique()
                ->values()
                ->all();
            sort($types);
            $sellerServiceTypesByCompany[(int) $company->id] = $types;
        }
        $activeSellerServiceTypes = $activeCompanyId !== null
            ? ($sellerServiceTypesByCompany[$activeCompanyId] ?? [])
            : [];

        // Phase 6A: per-company module visibility map. Default-allow semantics —
        // a module appears here only when an explicit row exists. Sidebar
        // enforcement in AdminShell denies a tab when its moduleKey is present
        // with value `false`. Super admins are never restricted (they don't
        // belong to a company in the permission sense).
        $moduleAllowMap = [];
        if ($activeCompanyId !== null && ! $isSuperAdmin) {
            $activeCompany = $sortedCompanies->firstWhere('id', $activeCompanyId);
            if ($activeCompany !== null) {
                foreach ($activeCompany->modulePermissions as $perm) {
                    $moduleAllowMap[(string) $perm->module_key] = (bool) $perm->is_allowed;
                }
            }
        }

        $canonicalRole = $adminAccess->canonicalRoleForUser($user);
        $canonicalRoles = array_values(array_unique(array_map(
            fn (string $roleName): string => $adminAccess->canonicalizeRoleName($roleName),
            $roleNames
        )));
        sort($canonicalRoles);

        $out = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'preferred_language' => $user->preferred_language,
            'avatar' => $user->avatar,
            'birth_date' => $user->birth_date?->format('Y-m-d'),
            'nationality' => $user->nationality,
            'location' => $user->location,
            'status' => $user->status,
            'intended_role' => $user->intended_role ?? null,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'last_login_at' => $user->last_login_at,
            'roles' => $roleNames,
            'canonical_roles' => $canonicalRoles,
            'canonical_role' => $canonicalRole,
            'is_super_admin' => $isSuperAdmin,
            'operator_statistics_platform_scope' => $operatorStatisticsPlatformScope,
            'is_statistics_elevated_only' => $isStatisticsElevatedOnly,
            'companies' => $sortedCompanies
                ->map(fn ($c) => [
                    'id' => (int) $c->id,
                    'name' => $c->name,
                    'seller_service_types' => $sellerServiceTypesByCompany[(int) $c->id] ?? [],
                ])
                ->all(),
            'context' => [
                'world' => $canonicalRole,
                'canonical_role' => $canonicalRole,
                'active_company_id' => $activeCompanyId,
                'active_seller_service_types' => $activeSellerServiceTypes,
                'module_permissions' => (object) $moduleAllowMap,
                'is_super_admin' => $isSuperAdmin,
                'is_platform_admin' => $isPlatformAdmin,
                'operator_statistics_platform_scope' => $operatorStatisticsPlatformScope,
                'is_statistics_elevated_only' => $isStatisticsElevatedOnly,
            ],
        ];

        // Super admins match backend platform-wide access; omit permissions so Phase 6 UI treats them as unrestricted.
        if (! $isSuperAdmin) {
            $permissions = array_keys($permissionKeys);
            sort($permissions);
            $out['permissions'] = $permissions;
        }

        return $out;
    }
}

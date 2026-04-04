<?php

namespace App\Http\Resources\Api;

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
        $user->loadMissing('memberships.role.permissions', 'companies');

        $adminAccess = app(AdminAccessService::class);
        $isSuperAdmin = $adminAccess->isSuperAdmin($user);
        $isPlatformAdmin = $adminAccess->isPlatformAdmin($user);
        $operatorStatisticsPlatformScope = $adminAccess->isAdminStatisticsSuperScope($user);
        $isStatisticsElevatedOnly = $adminAccess->isStatisticsElevatedOnly($user);

        $roleNames = [];
        $permissionKeys = [];
        foreach ($user->memberships as $membership) {
            $role = $membership->role;
            if ($role === null) {
                continue;
            }
            $roleNames[] = $role->name;
            foreach ($role->permissions as $permission) {
                $permissionKeys[$permission->name] = true;
            }
        }

        $roleNames = array_values(array_unique($roleNames));
        sort($roleNames);

        $sortedCompanies = $user->companies->sortBy('id')->values();
        $activeCompanyId = $sortedCompanies->isEmpty()
            ? null
            : (int) $sortedCompanies->first()->id;

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
            'status' => $user->status,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
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
                ])
                ->all(),
            'context' => [
                'world' => $canonicalRole,
                'canonical_role' => $canonicalRole,
                'active_company_id' => $activeCompanyId,
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

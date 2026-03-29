<?php

namespace App\Services\Admin;

use App\Models\Company;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AdminAccessService
{
    public function isSuperAdmin(User $user): bool
    {
        return $user->memberships()
            ->whereHas('role', function (Builder $query): void {
                $query->whereIn('name', ['super_admin', 'zulu_super_admin']);
            })
            ->orWhereHas('role.permissions', function (Builder $query): void {
                $query->whereIn('name', ['platform.admin', 'platform.manage', 'super_admin']);
            })
            ->exists();
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
        $memberships = UserCompany::query()
            ->where('user_id', $user->id)
            ->whereNotNull('role_id')
            ->with('role.permissions')
            ->get();

        $permissionNames = $memberships
            ->map(fn ($m) => $m->role)
            ->filter()
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->unique()
            ->all();

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

        $memberships = UserCompany::query()
            ->where('user_id', $user->id)
            ->whereNotNull('role_id')
            ->get();

        $firstMembership = $memberships->first();

        if ($firstMembership === null) {
            return null;
        }

        return (int) $firstMembership->company_id;
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

        return $user->belongsToCompany($companyId)
            && $user->hasCompanyPermission($companyId, $permission);
    }

    /**
     * Company ids for commerce list endpoints (matches company list super-admin scope: all tenants).
     *
     * @return list<int>
     */
    public function companyIdsForCommerceList(User $user, string $viewPermission): array
    {
        if ($this->isSuperAdmin($user)) {
            return $this->allCompanyIdsOrdered();
        }

        $ids = [];
        foreach ($user->companies as $company) {
            if ($user->hasCompanyPermission((int) $company->id, $viewPermission)) {
                $ids[] = (int) $company->id;
            }
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    public function allCompanyIdsOrdered(): array
    {
        return Company::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}

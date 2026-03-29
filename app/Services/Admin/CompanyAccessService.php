<?php

namespace App\Services\Admin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CompanyAccessService
{
    public function __construct(
        private readonly AdminAccessService $adminAccessService
    ) {
    }

    public function isCompanyAdmin(User $user, Company $company): bool
    {
        return $user->memberships()
            ->where('company_id', $company->id)
            ->where(function (Builder $membershipQuery): void {
                $membershipQuery
                    ->whereHas('role', function (Builder $roleQuery): void {
                        $roleQuery->whereIn('name', ['company_admin', 'admin']);
                    })
                    ->orWhereHas('role.permissions', function (Builder $permissionQuery): void {
                        $permissionQuery->whereIn('name', ['company.admin', 'company.manage']);
                    });
            })
            ->exists();
    }

    public function canManageCompany(User $user, Company $company): bool
    {
        return $this->adminAccessService->isSuperAdmin($user)
            || $this->isCompanyAdmin($user, $company);
    }
}

<?php

namespace App\Services\Admin;

use App\Models\Company;
use App\Models\User;

class CompanyAccessService
{
    public function __construct(
        private readonly AdminAccessService $adminAccessService
    ) {}

    public function isCompanyAdmin(User $user, Company $company): bool
    {
        return $this->adminAccessService->isOperatorAdmin($user, (int) $company->id);
    }

    public function canManageCompany(User $user, Company $company): bool
    {
        return $this->adminAccessService->isSuperAdmin($user)
            || $this->isCompanyAdmin($user, $company);
    }

    public function canAccessCompany(User $user, Company $company, ?string $permission = null): bool
    {
        return $this->adminAccessService->canAccessCompanyScopedResource($user, (int) $company->id, $permission);
    }

    public function resolveOperatorAdminCompanyId(User $user): ?int
    {
        if ($this->adminAccessService->isSuperAdmin($user)) {
            return null;
        }

        return $this->adminAccessService->resolveFirstOperatorAdminCompanyId($user);
    }
}

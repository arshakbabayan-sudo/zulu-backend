<?php

namespace App\Services\Admin;

use App\Models\Company;
use App\Models\User;
use App\Models\UserCompany;

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

    /**
     * §7 fix — seller status (companies/{id}/seller-applications) must be
     * reachable by agent-owners too: an agency owner's membership role is
     * `agent`, which canManageCompany (operator-tier roles only) 403s.
     * Agents only ever reach their OWN company's seller status here.
     */
    public function canManageSellerStatus(User $user, Company $company): bool
    {
        if ($this->canManageCompany($user, $company)) {
            return true;
        }

        return UserCompany::query()
            ->where('user_id', $user->id)
            ->where('company_id', $company->id)
            ->whereHas('role', fn ($query) => $query->where('name', 'agent'))
            ->exists();
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

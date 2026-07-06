<?php

namespace App\Services\UserAccount;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use App\Services\Admin\AdminAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Super-admin-only role changer for the platform-admin People panel.
 *
 * Mirrors the attach/update pattern of CompanyApplicationApprovalService
 * (companies()->attach with role_id pivot) and CompanyController::updateUserRole
 * (resolve Role by name, write user_company.role_id), but this path is
 * super-admin-only so it is NOT bound by the company-rank ceiling those
 * company-scoped controllers enforce.
 *
 * The controller (PlatformAdminController::changeUserRole) owns the auth /
 * self-change / last-super-admin guards; this service owns the data-shape
 * decisions (resolve role, attach vs update vs detach, platform-company lookup,
 * intended_role marker) and wraps every write in a single transaction.
 */
class UserRoleChangeService
{
    /** @var list<string> */
    private const INTENDED_ROLE_VALUES = ['operator', 'agent'];

    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    /**
     * Apply a role change to $target on behalf of $actor.
     *
     * @param  bool  $intendedRoleProvided  whether `intended_role` was present in
     *   the request body at all (distinguishes "clear it to null" from "leave it
     *   untouched"); $intendedRole carries the value when it was provided.
     * @return array{user_id: int, role_name: string|null, company_id: int|null, canonical_role: string}
     *
     * @throws ValidationException
     */
    public function changeRole(
        User $target,
        ?string $roleName,
        ?int $companyId,
        mixed $intendedRole,
        User $actor,
        bool $intendedRoleProvided = false,
    ): array {
        // Resolve the target role up-front (outside the transaction) so an
        // unknown name is a clean 422 before we touch anything. Null = detach.
        $role = null;
        if ($roleName !== null) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role === null) {
                throw ValidationException::withMessages([
                    'role_name' => ['Unknown role.'],
                ]);
            }
        }

        // Validate the optional intended_role marker independently of the role
        // change (contract: operator|agent|null).
        if ($intendedRoleProvided && $intendedRole !== null
            && ! in_array($intendedRole, self::INTENDED_ROLE_VALUES, true)) {
            throw ValidationException::withMessages([
                'intended_role' => ['intended_role must be one of: operator, agent, or null.'],
            ]);
        }

        $resolvedCompanyId = DB::transaction(function () use (
            $target,
            $role,
            $roleName,
            $companyId,
            $intendedRole,
            $intendedRoleProvided,
        ): ?int {
            $companyIdOut = null;

            if ($roleName === null) {
                // DETACH: remove the target's role-bound membership(s). Keep it
                // simple + safe: if a companyId is given, detach only that one;
                // if the user has exactly one membership, detach it; if multiple
                // and no companyId, detach all role-bound memberships (the caller
                // asked to strip the role entirely).
                $companyIdOut = $this->detachMemberships($target, $companyId);
            } else {
                // $role is non-null here (resolved above).
                $companyIdOut = $this->assignRole($target, $role, $companyId);
            }

            // intended_role marker — independent of the role change. Only touch
            // it when the key was present in the request.
            if ($intendedRoleProvided) {
                $target->intended_role = $intendedRole === null ? null : (string) $intendedRole;
                $target->save();
            }

            return $companyIdOut;
        });

        // Recompute canonical_role on a fresh instance (memberships changed).
        $fresh = User::query()->with('memberships.role')->findOrFail($target->id);
        $canonical = $this->adminAccessService->canonicalRoleForUser($fresh);

        return [
            'user_id' => (int) $target->id,
            'role_name' => $roleName,
            'company_id' => $resolvedCompanyId,
            'canonical_role' => $canonical,
        ];
    }

    /**
     * Resolve which company the role should live on, then attach a new
     * membership or update the existing one's role_id. Returns the company id
     * the membership ended up on.
     *
     * @throws ValidationException
     */
    private function assignRole(User $target, Role $role, ?int $companyId): int
    {
        $memberships = UserCompany::query()
            ->where('user_id', $target->id)
            ->get();

        // Existing membership path — update in place.
        if ($memberships->isNotEmpty()) {
            // If a companyId was given AND matches one of the memberships, update
            // that specific one; otherwise fall back to the single membership.
            $membership = null;
            if ($companyId !== null) {
                $membership = $memberships->first(
                    fn (UserCompany $m): bool => (int) $m->company_id === $companyId
                );
            }
            if ($membership === null && $memberships->count() === 1) {
                $membership = $memberships->first();
            }
            if ($membership === null) {
                // Multiple memberships and none matched the given company (or no
                // company given) — ambiguous, force the caller to disambiguate.
                throw ValidationException::withMessages([
                    'company_id' => ['This user belongs to several companies; specify company_id to pick which membership to change.'],
                ]);
            }

            $membership->role_id = $role->id;
            $membership->save();

            return (int) $membership->company_id;
        }

        // No membership — attaching a brand-new one.
        // Platform-scoped roles (platform_admin / super_admin) point at the
        // platform company (mirror recreate-claude migration lookup); a company
        // may be given but a platform company takes precedence if resolvable.
        if ($role->scope === Role::SCOPE_PLATFORM) {
            $platformCompanyId = $this->resolvePlatformCompanyId($companyId);
            if ($platformCompanyId === null) {
                throw ValidationException::withMessages([
                    'company_id' => ['No platform company is configured to attach this platform role.'],
                ]);
            }

            $target->companies()->attach($platformCompanyId, ['role_id' => $role->id]);

            return $platformCompanyId;
        }

        // Company-scoped role on a membership-less user → company_id is required.
        if ($companyId === null) {
            throw ValidationException::withMessages([
                'company_id' => ['company_id is required to attach this user to a company for the selected role.'],
            ]);
        }

        $company = Company::query()->find($companyId);
        if ($company === null) {
            throw ValidationException::withMessages([
                'company_id' => ['Company not found.'],
            ]);
        }

        $target->companies()->attach($company->id, ['role_id' => $role->id]);

        return (int) $company->id;
    }

    /**
     * Detach role-bound membership(s). Returns the company id detached from when
     * unambiguous (single membership or explicit companyId), else null.
     */
    private function detachMemberships(User $target, ?int $companyId): ?int
    {
        $query = UserCompany::query()->where('user_id', $target->id);

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
            $rows = $query->get();
            foreach ($rows as $row) {
                $row->delete();
            }

            return $rows->isNotEmpty() ? $companyId : null;
        }

        $rows = $query->get();
        if ($rows->count() === 1) {
            $only = $rows->first();
            $onlyCompanyId = (int) $only->company_id;
            $only->delete();

            return $onlyCompanyId;
        }

        // Zero or multiple memberships and no companyId given — strip them all.
        foreach ($rows as $row) {
            $row->delete();
        }

        return null;
    }

    /**
     * Platform company id for a platform-scoped role attach. Prefers the passed
     * companyId when it points to an existing company, then a type='platform'
     * company, then the first company (mirrors recreate-claude migration).
     */
    private function resolvePlatformCompanyId(?int $companyId): ?int
    {
        if ($companyId !== null && Company::query()->whereKey($companyId)->exists()) {
            return $companyId;
        }

        $platform = Company::query()->where('type', 'platform')->value('id');
        if ($platform !== null) {
            return (int) $platform;
        }

        $first = Company::query()->orderBy('id')->value('id');

        return $first !== null ? (int) $first : null;
    }
}

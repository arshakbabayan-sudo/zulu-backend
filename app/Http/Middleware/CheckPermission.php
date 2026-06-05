<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Services\Admin\CompanyAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase Գ.6 / Bucket D.4 — granular permission gate.
 *
 * Usage: ->middleware('permission:offers.create'). Super-admins and platform
 * admins are unrestricted (cross-tenant). For tenant users the active company
 * is resolved from the {company} route binding when present, else the user's
 * first managed company, and the effective permission (role baseline adjusted
 * by per-employee overrides) is checked via User::hasCompanyPermission.
 *
 * Apply incrementally — only to routes whose controller does not already gate
 * the same permission inline, and only after confirming production roles grant
 * it (avoid locking operators out). Override-deny is already enforced on every
 * endpoint that calls hasCompanyPermission today.
 */
class CheckPermission
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private CompanyAccessService $companyAccessService,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        if ($this->adminAccessService->isSuperAdmin($user) || $this->adminAccessService->isPlatformAdmin($user)) {
            return $next($request);
        }

        $companyId = $this->resolveCompanyId($request, $user);
        if ($companyId !== null && $user->hasCompanyPermission($companyId, $permission)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
            'errors' => ['permission' => [$permission]],
        ], 403);
    }

    private function resolveCompanyId(Request $request, User $user): ?int
    {
        $routeCompany = $request->route('company');
        if (is_object($routeCompany) && isset($routeCompany->id)) {
            return (int) $routeCompany->id;
        }
        if (is_numeric($routeCompany)) {
            return (int) $routeCompany;
        }

        $companyId = $this->companyAccessService->resolveOperatorAdminCompanyId($user);
        if ($companyId !== null) {
            return $companyId;
        }

        // RBAC #2 fix: resolveOperatorAdminCompanyId only resolves a company for
        // "operator admin" roles, so plain tenants (e.g. agents) got null here and
        // were 403'd on EVERY gated route even when their role holds the permission.
        // Fall back to the user's first company membership so the permission check
        // runs against the right tenant. (Super/platform already bypassed above.)
        $firstCompanyId = $user->memberships()
            ->whereNotNull('role_id')
            ->orderBy('id')
            ->value('company_id');

        return $firstCompanyId !== null ? (int) $firstCompanyId : null;
    }
}

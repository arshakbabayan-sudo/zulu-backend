<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces platform-admin authorisation at the route-group level.
 *
 * Previously each platform-admin controller method called a private
 * `denyUnlessPlatformAdmin($request)` helper that was copy-pasted into
 * 17 controllers (I1 audit finding F-4). Any new controller method that
 * forgot to call the helper would be silently exposed to every
 * Sanctum-authenticated user — including freshly-registered customers.
 *
 * This middleware moves the check up to the route definition so the
 * gate is impossible to forget. The in-controller `denyUnlessPlatformAdmin`
 * helpers stay in place for now as defence-in-depth; they can be removed
 * in a follow-up sweep once production verifies the middleware path.
 *
 * Apply to a route group via the `platform-admin` alias registered in
 * `bootstrap/app.php`:
 *
 *   Route::middleware(['auth:sanctum', 'platform-admin'])->prefix('platform-admin')->group(function () {
 *       // every route inside is guarded
 *   });
 */
class EnsurePlatformAdmin
{
    /**
     * Phase 6 — exact route suffixes (after `api/platform-admin/`) that a
     * non-staff operator/agent may reach. DENY-BY-DEFAULT: only GET requests
     * to these tenant-SCOPED LIST endpoints are allowed; everything else
     * (writes, super-only data, not-yet-scoped stats, detail-by-id without an
     * ownership check) stays 403 for operators. Super-admins and platform
     * staff (isPlatformAdmin) bypass this list entirely.
     *
     * Each entry's controller already filters by visibleCompanyIds (Phase
     * 0/1/2) so an operator sees ONLY their own company's rows. Only add a new
     * path here once its query is provably tenant-scoped.
     *
     * @var list<string>
     */
    private const OPERATOR_READABLE = [
        'bookings',
        'bookings/stats',
        'package-orders',
        'package-orders/stats',
        'payments',
        'vouchers',
        'insurance/products',
        'insurance/policies',
        'seller-applications',
        'contracts',
        'connections',
        'webhooks/subscriptions',
        'users',
        'companies',
        'audit-logs',
        'crm/deals',
        'crm/activities',
        'crm/team',
        'crm/stats',
        'crm/settings',
    ];

    /**
     * Phase 6.2 — detail (show-by-id) GETs operators may reach. Each backing
     * show() enforces per-row ownership (the row must belong to the caller's
     * visibleCompanyIds, else 404), so an operator can open ONLY their own
     * rows from a list. Patterns because the path carries a dynamic id.
     *
     * @var list<string>
     */
    private const OPERATOR_READABLE_PATTERNS = [
        '#^bookings/[^/]+$#',
        '#^companies/\d+$#',
        '#^users/\d+$#',
    ];

    public function __construct(private AdminAccessService $adminAccessService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Super-admins + genuine platform staff: full group access (their data
        // is scoped inside the controllers via visibleCompanyIds).
        if ($this->adminAccessService->isPlatformAdmin($user)) {
            return $next($request);
        }

        // Operators/agents (any role-bound membership, NOT a B2C customer):
        // Phase 6 admits them to the allowlisted, tenant-scoped READ endpoints
        // so they see their own company's data in the admin panel. Writes and
        // everything off the list stay 403 (no cross-tenant mutation, no leak
        // of unscoped/super-only data).
        if ($this->adminAccessService->canAccessAdminPanel($user)
            && $this->isOperatorReadable($request)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Forbidden',
        ], 403);
    }

    private function isOperatorReadable(Request $request): bool
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }

        $suffix = ltrim(substr($request->path(), strlen('api/platform-admin')), '/');

        if (in_array($suffix, self::OPERATOR_READABLE, true)) {
            return true;
        }

        foreach (self::OPERATOR_READABLE_PATTERNS as $pattern) {
            if (preg_match($pattern, $suffix) === 1) {
                return true;
            }
        }

        return false;
    }
}

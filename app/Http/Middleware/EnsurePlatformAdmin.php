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
        'crm/customers',
        'crm/customers/stats',
        'crm/leads',
        'crm/leads/stats',
        'crm/segments',
        'stats',
        'finance-summary',
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
        // Segment contacts — CrmController::segmentContacts re-checks the
        // segment belongs to the caller's company scope.
        '#^crm/segments/\d+/contacts$#',
    ];

    /**
     * Phase 7 (2026-06-09) — tenant WRITE endpoints a company OWNER (operator/
     * agent) may reach to manage their OWN company. Unlike the read allowlist
     * these are MUTATIONS, so the backing controller MUST re-check ownership
     * (canManageCompany on the target company + the row belongs to it) — this
     * middleware only ADMITS the request; the controller is the real gate.
     * Super-admins + platform staff bypass above. Keep this list tiny and only
     * add a path whose controller provably enforces company scope.
     *
     * @var array<string, list<string>> HTTP method => suffix regex patterns
     */
    private const OPERATOR_WRITABLE_PATTERNS = [
        'PUT' => [
            // Company owner sets one of their OWN employees' pay.
            // CrmController::setCompensation enforces canManageCompany + member.
            '#^crm/team/\d+/compensation$#',
        ],
        // CRM Leads + Segments (2026-06-12, roadmap §4). Leads: any company
        // member may work leads — CrmController enforces company scope +
        // Layer-B ownership (canWriteCompanyRows/denyUnlessLeadWritable).
        // Segments: CrmController::canManageSegmentsOf additionally requires
        // canManageCompany (owner/manager), so plain employees stay read-only.
        'POST' => [
            '#^crm/leads$#',
            '#^crm/leads/\d+/convert$#',
            '#^crm/segments$#',
            '#^crm/segments/\d+/members$#',
        ],
        'PATCH' => [
            '#^crm/leads/\d+$#',
            '#^crm/segments/\d+$#',
        ],
        'DELETE' => [
            '#^crm/leads/\d+$#',
            '#^crm/segments/\d+$#',
            '#^crm/segments/\d+/members/\d+$#',
        ],
    ];

    public function __construct(private AdminAccessService $adminAccessService) {}

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
            && ($this->isOperatorReadable($request) || $this->isOperatorWritable($request))) {
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

    private function isOperatorWritable(Request $request): bool
    {
        $patterns = self::OPERATOR_WRITABLE_PATTERNS[$request->method()] ?? [];
        if ($patterns === []) {
            return false;
        }

        $suffix = ltrim(substr($request->path(), strlen('api/platform-admin')), '/');

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $suffix) === 1) {
                return true;
            }
        }

        return false;
    }
}

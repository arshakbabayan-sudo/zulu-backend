<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApprovalResource;
use App\Http\Resources\Api\CompanyResource;
use App\Http\Resources\Api\OrderResource;
use App\Http\Resources\Api\PackageResource;
use App\Models\Approval;
use App\Models\Company;
use App\Models\CompanyApplication;
use App\Models\CompanySellerApplication;
use App\Models\CompanySellerPermission;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageHomepageFeature;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Review;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNote;
use App\Services\Admin\AdminAccessService;
use App\Services\Admin\PlatformAdminService;
use App\Services\Approvals\ApprovalService;
use App\Services\Approvals\CompanyApplicationApprovalService;
use App\Services\Companies\CompanyService;
use App\Services\Companies\SellerApplicationService;
use App\Services\Infrastructure\PlatformSettingsService;
use App\Services\Reviews\ReviewService;
use App\Services\UserAccount\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformAdminController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService,
        private CompanyService $companyService,
    ) {}

    public function stats(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        // Phase 5: scope the dashboard numbers to the caller's companies (super
        // = platform-wide). Operator dashboard shows THEIR numbers.
        [$scopeIds, $scopeSuffix] = $this->adminAccessService->statsScope($request->user());

        // Roadmap 10.06 §5 — optional range for the dashboard date picker;
        // whitelisted so a bad value silently falls back to all-time.
        $range = (string) $request->query('range', '');
        if (! in_array($range, ['7d', '30d', '90d', 'year'], true)) {
            $range = null;
        }

        return response()->json([
            'success' => true,
            'data' => $service->getPlatformStats($scopeIds, $scopeSuffix, $range),
        ]);
    }

    /**
     * GET /platform-admin/search?q= — roadmap 10.06 §5 ("Search anything").
     *
     * One query, three entity groups (companies / users / bookings), max 5
     * hits each, every hit shaped {id, label, href} so the header dropdown
     * can render + deep-link without entity-specific logic. Tenant-scoped:
     * a non-super caller only sees rows of their own companies.
     */
    public function globalSearch(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([
                'success' => true,
                'data' => ['companies' => [], 'users' => [], 'bookings' => []],
            ]);
        }

        [$scopeIds] = $this->adminAccessService->statsScope($request->user());
        // LOWER() LIKE instead of ILIKE so the query runs on both Postgres
        // (prod) and SQLite (test suite).
        $like = '%'.mb_strtolower(str_replace(['%', '_'], ['\\%', '\\_'], $q)).'%';

        $companies = DB::table('companies')
            ->when($scopeIds !== null, fn ($qq) => $qq->whereIn('id', $scopeIds ?: [-1]))
            ->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(legal_name) LIKE ?', [$like]);
            })
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name'])
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'label' => (string) $c->name,
                'href' => "/platform/companies/{$c->id}",
            ])
            ->values();

        $users = DB::table('users')
            ->when($scopeIds !== null, function ($qq) use ($scopeIds) {
                $qq->whereIn('id', DB::table('user_company')
                    ->whereIn('company_id', $scopeIds ?: [-1])
                    ->pluck('user_id'));
            })
            ->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
            })
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => [
                'id' => (int) $u->id,
                'label' => trim(($u->name ?? '').' · '.($u->email ?? ''), ' ·'),
                'href' => "/platform/users/{$u->id}",
            ])
            ->values();

        $bookings = DB::table('orders')
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->when($scopeIds !== null, function ($qq) use ($scopeIds) {
                $qq->where(function ($w) use ($scopeIds) {
                    $w->whereIn('orders.company_id', $scopeIds ?: [-1])
                        ->orWhereIn('orders.agent_company_id', $scopeIds ?: [-1]);
                });
            })
            ->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(orders.order_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(users.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(users.email) LIKE ?', [$like]);
            })
            ->orderByDesc('orders.created_at')
            ->limit(5)
            ->get(['orders.id', 'orders.order_number', 'users.name AS user_name'])
            ->map(fn ($o) => [
                'id' => (string) $o->id,
                'label' => trim(($o->order_number ?? $o->id).' · '.($o->user_name ?? ''), ' ·'),
                'href' => '/platform/bookings?q='.urlencode((string) ($o->order_number ?? '')),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'companies' => $companies,
                'users' => $users,
                'bookings' => $bookings,
            ],
        ]);
    }

    public function companies(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $filters = [
            'governance_status' => $request->filled('governance_status') ? (string) $request->query('governance_status') : null,
            'is_seller' => $this->parseOptionalBool($request->query('is_seller')),
            'search' => $request->filled('search') ? (string) $request->query('search') : null,
            'type' => $request->filled('type') ? (string) $request->query('type') : null,
            'sort_by' => $request->filled('sort_by') ? (string) $request->query('sort_by') : null,
            'sort_dir' => $request->filled('sort_dir') ? (string) $request->query('sort_dir') : null,
            'archive_filter' => $request->filled('archive_filter') ? (string) $request->query('archive_filter') : null,
        ];

        $perPage = $this->commerceListPerPage($request);
        $cleanFilters = array_filter(
            $filters,
            static fn ($v) => $v !== null && $v !== ''
        );

        // Tenant scope: non-super callers see only their own company row(s).
        // Set after array_filter so an empty list survives as a real [].
        $caller = $request->user();
        if ($caller !== null && ! $this->adminAccessService->isSuperAdmin($caller)) {
            $cleanFilters['scope_company_ids'] = $this->adminAccessService->visibleCompanyIds($caller);
        }

        $paginator = $service->listCompanies($cleanFilters, $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, CompanyResource::class);
    }

    /**
     * Phase 6.2 — null = caller may see every company (super-admin); otherwise
     * the company-id allowlist the caller is scoped to. Used by detail (show)
     * endpoints so an operator can open ONLY their own rows by id (the list
     * endpoints are already scoped; this closes the by-id back door).
     *
     * @return ?list<int>
     */
    private function callerVisibleCompanyIds(Request $request): ?array
    {
        $user = $request->user();
        if ($user === null || $this->adminAccessService->isSuperAdmin($user)) {
            return null;
        }

        return $this->adminAccessService->visibleCompanyIds($user);
    }

    public function showCompany(Request $request, Company $company): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        // Phase 6.2 ownership: a non-super caller may only open their own company.
        $vis = $this->callerVisibleCompanyIds($request);
        if ($vis !== null && ! in_array((int) $company->id, $vis, true)) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $company->loadCount([
            'sellerPermissions as active_seller_permissions_count' => function ($q): void {
                $q->where('status', 'active');
            },
        ]);

        return response()->json([
            'success' => true,
            'data' => CompanyResource::make($company)->toArray($request),
        ]);
    }

    /**
     * Admin edit of a company's editable profile fields (identity + contact +
     * address). Super-admin or the company's own scoped platform admin only.
     * Lifecycle (governance_status), seller flags, archive, partner visibility
     * and Stripe/Drive state are each managed by their own dedicated endpoints
     * — this one only covers the plain descriptive fields.
     */
    public function updateCompanyProfile(Request $request, Company $company): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        // Ownership: a non-super caller may only edit their own company.
        $vis = $this->callerVisibleCompanyIds($request);
        if ($vis !== null && ! in_array((int) $company->id, $vis, true)) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(Company::TYPES)],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'country' => ['sometimes', 'nullable', 'string', 'max:64'],
            'city' => ['sometimes', 'nullable', 'string', 'max:128'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'website' => ['sometimes', 'nullable', 'string', 'max:512'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        foreach ($validated as $field => $value) {
            $company->{$field} = $value;
        }
        $company->save();

        return response()->json([
            'success' => true,
            'message' => 'Company profile updated',
            'data' => CompanyResource::make($company->fresh())->toArray($request),
        ]);
    }

    public function changeGovernance(Request $request, Company $company, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'governance_status' => ['required', 'string', Rule::in(Company::GOVERNANCE_STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $service->changeCompanyGovernanceStatus(
            $company,
            $request->user(),
            $validated['governance_status'],
            $validated['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => CompanyResource::make($updated)->toArray($request),
        ]);
    }

    /**
     * Bulk sync active seller module permissions (parity with PATCH admin/companies/{company}/permissions).
     */
    public function updateCompanyPermissions(Request $request, Company $company): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(CompanySellerPermission::SERVICE_TYPES)],
        ]);

        $requested = array_values(array_unique($validated['permissions'] ?? []));
        $updatedCompany = $this->companyService->syncActiveSellerPermissionServiceTypes(
            $company,
            $request->user(),
            $requested
        );

        $activePermissions = $updatedCompany->sellerPermissions()
            ->where('status', 'active')
            ->pluck('service_type')
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Company permissions updated successfully',
            'data' => [
                'company' => CompanyResource::make($updatedCompany)->toArray($request),
                'active_permissions' => $activePermissions,
            ],
        ]);
    }

    /**
     * Toggle company.is_seller (parity with PATCH admin/companies/{company}/toggle-seller).
     */
    public function toggleCompanySellerStatus(Request $request, Company $company): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $updated = $this->companyService->toggleSellerEnabledFlag($company);

        return response()->json([
            'success' => true,
            'message' => 'Seller status updated',
            'data' => [
                'is_seller' => (bool) $updated->is_seller,
                'company' => CompanyResource::make($updated)->toArray($request),
            ],
        ]);
    }

    /**
     * Update partner visibility settings: logo URL + is_partner_visible flag.
     * Feeds the public home page partner strip (PublicPageController::homePage).
     */
    public function updateCompanyPartnerSettings(Request $request, Company $company): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'logo' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'is_partner_visible' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('logo', $validated)) {
            $company->logo = $validated['logo'];
        }
        if (array_key_exists('is_partner_visible', $validated)) {
            $company->is_partner_visible = (bool) $validated['is_partner_visible'];
        }
        $company->save();

        return response()->json([
            'success' => true,
            'data' => CompanyResource::make($company->fresh())->toArray($request),
        ]);
    }

    public function approvals(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $filters = array_filter([
            'status' => $request->filled('status') ? (string) $request->query('status') : null,
            'entity_type' => $request->filled('entity_type') ? (string) $request->query('entity_type') : null,
        ], static fn ($v) => $v !== null && $v !== '');

        $perPage = $this->commerceListPerPage($request);
        $paginator = $service->listApprovals($filters, $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, ApprovalResource::class);
    }

    public function approveApproval(Request $request, Approval $approval, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $fresh = $service->approveApproval(
            $approval,
            $request->user(),
            $validated['decision_notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => ApprovalResource::make($fresh)->toArray($request),
        ]);
    }

    public function rejectApproval(Request $request, Approval $approval, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $fresh = $service->rejectApproval(
            $approval,
            $request->user(),
            $validated['decision_notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => ApprovalResource::make($fresh)->toArray($request),
        ]);
    }

    /**
     * POST /api/platform-admin/approvals/bulk-approve
     *
     * Body: { ids: number[], decision_notes?: string }
     * Returns per-id success/failure outcome (Sprint 64, PART 21).
     */
    public function bulkApproveApprovals(Request $request, ApprovalService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'decision_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $results = $service->approveBulk(
            array_map('intval', (array) $validated['ids']),
            $request->user(),
            $validated['decision_notes'] ?? null
        );

        $successCount = count(array_filter($results, fn ($r) => $r['ok']));

        return response()->json([
            'success' => true,
            'data' => [
                'requested' => count($results),
                'succeeded' => $successCount,
                'results' => $results,
            ],
        ]);
    }

    public function packageOrders(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $filters = array_filter([
            'status' => $request->filled('status') ? (string) $request->query('status') : null,
            'payment_status' => $request->filled('payment_status') ? (string) $request->query('payment_status') : null,
            'company_id' => $request->filled('company_id') ? (int) $request->query('company_id') : null,
        ], static fn ($v) => $v !== null && $v !== '');

        // Tenant scope (2026-06-02): non-super callers see only their own
        // companies' package orders. Set after array_filter so an empty
        // "owns no company" list survives as a real [] (→ zero rows).
        $user = $request->user();
        if ($user !== null && ! $this->adminAccessService->isSuperAdmin($user)) {
            $filters['scope_company_ids'] = $this->adminAccessService->visibleCompanyIds($user);
            // Phase 3 Layer-B: a plain employee sees only orders they sold;
            // company owner / holder of package_orders.view_all sees the
            // whole company (resolver returns null → no attribution filter).
            $filters['scope_attribution_user_id'] = $this->adminAccessService
                ->employeeRowScopeUserId($user, 'package_orders.view_all');
        }

        $perPage = $this->commerceListPerPage($request);
        $paginator = $service->listAllPackageOrders($filters, $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, OrderResource::class);
    }

    /**
     * Bookings group v2 — GET /platform-admin/bookings.
     *
     * "All bookings" view: lists `orders` excluding package orders.
     * Filters: status / from / to / search / company_id / service_type.
     */
    public function bookings(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $filters = array_filter([
            'status' => $request->filled('status') ? (string) $request->query('status') : null,
            'from' => $request->filled('from') ? (string) $request->query('from') : null,
            'to' => $request->filled('to') ? (string) $request->query('to') : null,
            'search' => $request->filled('search') ? (string) $request->query('search') : null,
            'company_id' => $request->filled('company_id') ? (int) $request->query('company_id') : null,
            // Phase 4G (2026-05-31) — pass user_id through so the user
            // detail page can fetch a customer's recent bookings inline.
            'user_id' => $request->filled('user_id') ? (int) $request->query('user_id') : null,
            'service_type' => $request->filled('service_type') ? (string) $request->query('service_type') : null,
        ], static fn ($v) => $v !== null && $v !== '');

        // Tenant scoping (2026-06-02): super-admins see every company's
        // bookings; everyone else — operator/agent, even when a platform.*
        // permission makes isPlatformAdmin() true — is restricted to their
        // own companies. Set AFTER array_filter so an empty "owns no company"
        // list survives as a real [] scope instead of being dropped (which
        // would fall through to unscoped = the leak Arshak reported).
        $user = $request->user();
        if ($user !== null && ! $this->adminAccessService->isSuperAdmin($user)) {
            $filters['scope_company_ids'] = $this->adminAccessService->visibleCompanyIds($user);
            // Phase 3 Layer-B: a plain employee sees only bookings they sold
            // (orders.sold_by_user_id); owner / bookings.view_all → whole
            // company (resolver null → no attribution filter).
            $filters['scope_attribution_user_id'] = $this->adminAccessService
                ->employeeRowScopeUserId($user, 'bookings.view_all');
        }

        $perPage = $this->commerceListPerPage($request);
        $paginator = $service->listAllBookings($filters, $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, OrderResource::class);
    }

    /**
     * Bookings group v2 — GET /platform-admin/bookings/{id}.
     *
     * Single-order detail for the new booking detail page (Phase 3B of the
     * 2026-05-31 menu restructure). Mirrors the shape returned by
     * confirmBooking / cancelBooking on success: OrderResource with `user`,
     * `company`, and `items` eager-loaded. 404 when the UUID does not match.
     */
    public function showBooking(Request $request, string $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $order = Order::query()->whereKey($id)->first();
        if ($order === null) {
            return response()->json(['success' => false, 'message' => 'Booking not found'], 404);
        }

        // Phase 6.2 ownership: a non-super caller may only open a booking of
        // their own company (seller via company_id OR referring agent).
        $vis = $this->callerVisibleCompanyIds($request);
        if ($vis !== null
            && ! in_array((int) $order->company_id, $vis, true)
            && ! in_array((int) $order->agent_company_id, $vis, true)) {
            return response()->json(['success' => false, 'message' => 'Booking not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new OrderResource($order->load(['user:id,name,email', 'company:id,name', 'items'])),
        ]);
    }

    /**
     * Bookings group v2 — POST /platform-admin/bookings/{id}/confirm.
     *
     * Transitions Order status pending_payment → confirmed. Idempotent for
     * already-confirmed/paid orders (no-op + 200).
     */
    public function confirmBooking(Request $request, string $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $order = Order::query()->whereKey($id)->first();
        if ($order === null) {
            return response()->json(['success' => false, 'message' => 'Booking not found'], 404);
        }

        if (! in_array($order->status, ['cart', 'pending_payment', 'paid', 'confirmed'], true)) {
            return response()->json(['success' => false, 'message' => 'Booking cannot be confirmed from status '.$order->status], 422);
        }

        $order->status = 'confirmed';
        $order->save();

        Cache::forget('bookings_stats_7d');
        Cache::forget('bookings_stats_30d');
        Cache::forget('bookings_stats_90d');

        return response()->json(['success' => true, 'data' => new OrderResource($order->refresh()->load(['user:id,name,email', 'company:id,name', 'items']))]);
    }

    /**
     * Bookings group v2 — POST /platform-admin/bookings/{id}/cancel.
     *
     * Transitions any non-terminal status to `cancelled`. Refunded/failed
     * already-terminal orders return 422.
     */
    public function cancelBooking(Request $request, string $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $order = Order::query()->whereKey($id)->first();
        if ($order === null) {
            return response()->json(['success' => false, 'message' => 'Booking not found'], 404);
        }

        if (in_array($order->status, ['cancelled', 'refunded'], true)) {
            return response()->json(['success' => false, 'message' => 'Booking already in terminal status'], 422);
        }

        $order->status = 'cancelled';
        $order->save();

        Cache::forget('bookings_stats_7d');
        Cache::forget('bookings_stats_30d');
        Cache::forget('bookings_stats_90d');

        return response()->json(['success' => true, 'data' => new OrderResource($order->refresh()->load(['user:id,name,email', 'company:id,name', 'items']))]);
    }

    public function payments(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        // Phase 7.7 — date range filters
        $filters = array_filter([
            'status' => $request->filled('status') ? (string) $request->query('status') : null,
            'from' => $request->filled('from') ? (string) $request->query('from') : null,
            'to' => $request->filled('to') ? (string) $request->query('to') : null,
        ], static fn ($v) => $v !== null && $v !== '');

        // Tenant scope: non-super callers only see payments on their own
        // companies' orders (seller or referring agent).
        $user = $request->user();
        if ($user !== null && ! $this->adminAccessService->isSuperAdmin($user)) {
            $filters['scope_company_ids'] = $this->adminAccessService->visibleCompanyIds($user);
        }

        $perPage = $this->commerceListPerPage($request);
        $paginator = $service->listAllPayments($filters, $perPage);

        return $this->paginatedPaymentsResponse($request, $paginator);
    }

    /**
     * Phase 7.7 — CSV export for /platform/payments with the same filters.
     */
    public function exportPaymentsCsv(Request $request, PlatformAdminService $service): StreamedResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            abort($deny->status(), $deny->getData(true)['message'] ?? 'Forbidden');
        }

        $filters = array_filter([
            'status' => $request->filled('status') ? (string) $request->query('status') : null,
            'from' => $request->filled('from') ? (string) $request->query('from') : null,
            'to' => $request->filled('to') ? (string) $request->query('to') : null,
        ], static fn ($v) => $v !== null && $v !== '');

        // Tenant scope: same as the list endpoint — non-super exports only
        // their own companies' payments.
        $user = $request->user();
        if ($user !== null && ! $this->adminAccessService->isSuperAdmin($user)) {
            $filters['scope_company_ids'] = $this->adminAccessService->visibleCompanyIds($user);
        }

        $filename = 'payments-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($service, $filters): void {
            $fh = fopen('php://output', 'w');
            fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($fh, [
                'id',
                'invoice_id',
                'invoice_reference',
                'amount',
                'currency',
                'status',
                'payment_method',
                'reference_code',
                'paid_at',
                'created_at',
            ]);

            $payments = $service->listPaymentsForExport($filters);
            foreach ($payments->take(10000) as $p) {
                fputcsv($fh, [
                    $p->id,
                    $p->invoice_id,
                    $p->invoice?->unique_booking_reference,
                    $p->amount,
                    $p->currency,
                    $p->status,
                    $p->payment_method,
                    $p->reference_code,
                    $p->paid_at?->toIso8601String(),
                    $p->created_at?->toIso8601String(),
                ]);
            }
            fclose($fh);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function financeSummary(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        // Phase 5: scope finance totals to the caller's companies (super = all).
        [$scopeIds, $scopeSuffix] = $this->adminAccessService->statsScope($request->user());

        return response()->json([
            'success' => true,
            'data' => $service->getFinanceSummary($scopeIds, $scopeSuffix),
        ]);
    }

    public function packages(Request $request, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $filters = array_filter([
            'status' => $request->filled('status') ? (string) $request->query('status') : null,
            'company_id' => $request->filled('company_id') ? (int) $request->query('company_id') : null,
        ], static fn ($v) => $v !== null && $v !== '');

        $perPage = $this->commerceListPerPage($request);
        $paginator = $service->listAllPackages($filters, $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, PackageResource::class);
    }

    // ─── User Management ────────────────────────────────────────────

    /**
     * Phase Զ.16 — true platform-wide user stats for the Users page's 4
     * stat cards (was current-page-only counts before).
     *
     * GET /api/platform-admin/users/stats
     *   → { total, active_today, new_7d, pending_verification }
     */
    public function userStats(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $oneDayAgo = now()->subDay();
        $sevenDaysAgo = now()->subDays(7);

        return response()->json([
            'success' => true,
            'data' => [
                'total' => User::query()->count(),
                'active_today' => User::query()
                    ->where('last_login_at', '>=', $oneDayAgo)
                    ->count(),
                'new_7d' => User::query()
                    ->where('created_at', '>=', $sevenDaysAgo)
                    ->count(),
                'pending_verification' => User::query()
                    ->where(function ($q): void {
                        $q->where('status', 'pending')
                            ->orWhereNull('email_verified_at');
                    })
                    ->count(),
            ],
        ]);
    }

    public function listUsers(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $perPage = $this->commerceListPerPage($request);
        // Phase 4C+ (2026-05-31, fixed 2026-05-31) — withCount('bookings') so
        // the Directory→People grid can render a Bookings column when the B2C
        // customers chip is active. The User model exposes the relation as
        // bookings() (not orders()), so the previous withCount('orders') in
        // commit 4a67bca caused a 500 on every users-list fetch.
        $query = User::query()->with('companies')->withCount('bookings')->orderByDesc('id');

        // Tenant scope: non-super callers see only users who belong to their own
        // companies (their staff), not every user on the platform.
        $caller = $request->user();
        if ($caller !== null && ! $this->adminAccessService->isSuperAdmin($caller)) {
            $ids = $this->adminAccessService->visibleCompanyIds($caller) ?: [0];
            $query->whereHas('companies', fn ($q) => $q->whereIn('companies.id', $ids));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        // Phase 6.4 — Type filter merges the previously-separate user views:
        //  - "customers" = B2C (no company membership)
        //  - "staff"     = operator/agent/admin (has company membership)
        //  - "unverified" = pending email verification
        $type = $request->filled('type') ? (string) $request->query('type') : null;
        if ($type === 'customers') {
            $query->whereDoesntHave('memberships');
        } elseif ($type === 'staff') {
            $query->whereHas('memberships');
        } elseif ($type === 'unverified') {
            $query->where(function ($q): void {
                $q->where('status', 'pending')->orWhereNull('email_verified_at');
            });
        }

        // Phase 7.5 — legacy `with_companies=1` shortcut (now superseded by type=staff).
        if ($request->boolean('with_companies')) {
            $query->whereHas('memberships');
        }

        $paginator = $query->paginate($perPage);

        $data = $paginator->getCollection()->map(fn (User $user): array => $this->platformAdminUserRow($user))->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * Phase 7.1 — B2C customers list.
     *
     * A "customer" here is a user with zero company memberships (so not an
     * operator / agent / platform admin). Same paginated/filterable shape as
     * listUsers; sortable by registration date or name.
     */
    public function listCustomers(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        // B2C customer registry is platform-wide — only super-admins browse it.
        $caller = $request->user();
        if ($caller !== null && ! $this->adminAccessService->isSuperAdmin($caller)) {
            return response()->json(['success' => true, 'data' => [], 'meta' => [
                'current_page' => 1, 'last_page' => 1, 'total' => 0,
                'per_page' => $this->commerceListPerPage($request),
            ]]);
        }

        $perPage = $this->commerceListPerPage($request);
        $query = User::query()
            ->whereDoesntHave('memberships')
            ->withCount('bookings as bookings_count')
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $paginator = $query->paginate($perPage);

        $data = $paginator->getCollection()->map(function (User $user): array {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
                'preferred_language' => $user->preferred_language,
                'nationality' => $user->nationality,
                'bookings_count' => (int) ($user->bookings_count ?? 0),
                'created_at' => $user->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * Phase 7.8 — unverified accounts. Returns users that need attention:
     * status='pending', or never confirmed their email (email_verified_at
     * IS NULL). Same shape as listUsers; sorted oldest first so the queue
     * surfaces stragglers.
     */
    public function listUnverifiedAccounts(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        // Unverified-accounts moderation is a platform-wide tool — super-only.
        $caller = $request->user();
        if ($caller !== null && ! $this->adminAccessService->isSuperAdmin($caller)) {
            return response()->json(['success' => true, 'data' => [], 'meta' => [
                'current_page' => 1, 'last_page' => 1, 'total' => 0,
                'per_page' => $this->commerceListPerPage($request),
            ]]);
        }

        $perPage = $this->commerceListPerPage($request);
        $query = User::query()
            ->with('companies')
            ->where(function ($q) {
                $q->where('status', 'pending')
                    ->orWhereNull('email_verified_at');
            })
            ->orderBy('created_at');

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        $paginator = $query->paginate($perPage);

        $data = $paginator->getCollection()->map(function (User $user): array {
            return array_merge($this->platformAdminUserRow($user), [
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'intended_role' => $user->intended_role ?? null,
            ]);
        })->values()->all();

        // Full-dataset stat cards for /platform/unverified. Computed over the
        // SAME "unverified" set the list uses (status=pending OR no verified
        // email) + the same search filter, so the cards never disagree with the
        // page. "intended_staff" = rows whose intended_role is set (the visitor
        // came in via a partner-register flow → operator/agent, i.e. staff).
        $statsBase = static function () use ($request) {
            $q = User::query()->where(function ($q): void {
                $q->where('status', 'pending')->orWhereNull('email_verified_at');
            });
            if ($request->filled('search')) {
                $search = (string) $request->query('search');
                $q->where(function ($w) use ($search): void {
                    $w->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            }

            return $q;
        };

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'stats' => [
                    'stale_30d' => (int) $statsBase()->where('created_at', '<', now()->subDays(30))->count(),
                    'new_7d' => (int) $statsBase()->where('created_at', '>=', now()->subDays(7))->count(),
                    'intended_staff' => (int) $statsBase()->whereNotNull('intended_role')->count(),
                ],
            ],
        ]);
    }

    public function showUser(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $user = User::query()->with('companies')->findOrFail($id);

        // Phase 6.2 ownership: a non-super caller may only open a user who
        // belongs to one of their own companies (their staff).
        $vis = $this->callerVisibleCompanyIds($request);
        if ($vis !== null) {
            $userCompanyIds = $user->companies->pluck('id')->map(fn ($cid) => (int) $cid)->all();
            if (count(array_intersect($vis, $userCompanyIds)) === 0) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }
        }

        $detail = $this->platformAdminUserDetail($user);

        // Directory customer detail (2026-06-03): the user's recent orders with
        // WHO they bought from (seller company) + referring agent + amount/status.
        // Answers "who bought what from whom" on the B2C customer detail view.
        $detail['recent_orders'] = $user->bookings()
            ->latest()
            ->limit(10)
            ->with(['company:id,name', 'agentCompany:id,name'])
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'status' => $o->status,
                'total' => $o->total,
                'currency' => $o->currency,
                'created_at' => $o->created_at?->toIso8601String(),
                'seller' => $o->company ? ['id' => $o->company->id, 'name' => $o->company->name] : null,
                'agent' => $o->agentCompany ? ['id' => $o->agentCompany->id, 'name' => $o->agentCompany->name] : null,
            ])->values()->all();

        // Loyalty (2026-06-04 admin v3): wire the Customer detail Loyalty tab +
        // Summary card. Each B2C customer has at most one LoyaltyAccount row;
        // sum the transaction columns to compute lifetime earned/redeemed (the
        // running balance is denormalised on the account itself).
        $loyalty = LoyaltyAccount::query()->where('user_id', $user->id)->first();
        if ($loyalty !== null) {
            // `loyalty_transactions.points` is always a positive int; `type`
            // separates earn/redeem/expire/adjust. Lifetime earned = sum of
            // earn rows; lifetime redeemed = sum of redeem rows.
            $earned = (int) LoyaltyTransaction::query()
                ->where('account_id', $loyalty->id)
                ->where('type', 'earn')
                ->sum('points');
            $redeemed = (int) LoyaltyTransaction::query()
                ->where('account_id', $loyalty->id)
                ->where('type', 'redeem')
                ->sum('points');
            $detail['loyalty'] = [
                'tier' => $loyalty->tier,
                'points_balance' => (int) $loyalty->points_balance,
                'points_earned' => $earned,
                'points_redeemed' => $redeemed,
            ];
        } else {
            $detail['loyalty'] = null;
        }

        // Admin notes — recent first, max 10 inline on detail.
        $detail['notes'] = UserNote::query()
            ->where('user_id', $user->id)
            ->with('author:id,name')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'body' => $n->body,
                'created_at' => $n->created_at?->toIso8601String(),
                'author' => $n->author ? ['id' => $n->author->id, 'name' => $n->author->name] : null,
            ])->values()->all();

        return response()->json([
            'success' => true,
            'data' => $detail,
        ]);
    }

    /** GET /platform-admin/users/{id}/notes — full notes list, paginated. */
    public function listUserNotes(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }
        $user = User::query()->findOrFail($id);
        $vis = $this->callerVisibleCompanyIds($request);
        if ($vis !== null) {
            $ucIds = $user->companies()->pluck('companies.id')->map(fn ($cid) => (int) $cid)->all();
            if (count(array_intersect($vis, $ucIds)) === 0) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }
        }
        $perPage = max(1, min(50, (int) $request->query('per_page', 20)));
        $page = UserNote::query()
            ->where('user_id', $user->id)
            ->with('author:id,name')
            ->latest()
            ->paginate($perPage);
        $data = $page->getCollection()->map(fn ($n) => [
            'id' => $n->id,
            'body' => $n->body,
            'created_at' => $n->created_at?->toIso8601String(),
            'author' => $n->author ? ['id' => $n->author->id, 'name' => $n->author->name] : null,
        ])->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** POST /platform-admin/users/{id}/notes — append a new admin note. */
    public function storeUserNote(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }
        $user = User::query()->findOrFail($id);
        $vis = $this->callerVisibleCompanyIds($request);
        if ($vis !== null) {
            $ucIds = $user->companies()->pluck('companies.id')->map(fn ($cid) => (int) $cid)->all();
            if (count(array_intersect($vis, $ucIds)) === 0) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }
        }
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);
        $note = UserNote::query()->create([
            'user_id' => $user->id,
            'author_user_id' => $request->user()?->id,
            'body' => $validated['body'],
        ]);
        $note->load('author:id,name');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $note->id,
                'body' => $note->body,
                'created_at' => $note->created_at?->toIso8601String(),
                'author' => $note->author ? ['id' => $note->author->id, 'name' => $note->author->name] : null,
            ],
        ], 201);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $user = User::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'preferred_language' => ['sometimes', 'nullable', 'string', 'max:8'],
            'birth_date' => ['sometimes', 'nullable', 'date'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'pending', 'suspended'])],
        ]);

        $user->fill($validated);
        $user->save();

        return response()->json([
            'success' => true,
            'data' => $this->platformAdminUserDetail($user->fresh(['companies'])),
        ]);
    }

    public function deactivateUser(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $user = User::query()->findOrFail($id);

        if ((int) $request->user()->id === (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate yourself.',
            ], 422);
        }

        $user->status = 'inactive';
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User deactivated.',
            'data' => [
                'message' => 'User deactivated.',
                'user' => $this->platformAdminUserRow($user->load('companies')),
            ],
        ]);
    }

    /**
     * Phase 7.1 — "Ջնջել" (default platform-admin action).
     * Anonymises PII on the user row + retained tables, revokes tokens,
     * soft-deletes. Reversible only in the sense that the row can be
     * un-trashed; the original PII is not recoverable.
     */
    public function anonymizeUser(Request $request, int $id, AccountDeletionService $deletionService): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $actor = $request->user();
        $user = User::query()->findOrFail($id);

        if ((int) $actor->id === (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot anonymize yourself.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $deletionService->adminAnonymize($user, $actor, $validated['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'User anonymized.',
            'data' => ['id' => $user->id],
        ]);
    }

    /**
     * Phase 7.2 — Archive company (super-admin only, mandatory reason).
     * Hides the company from default admin listings but preserves linked
     * orders/contracts/inventory rows for financial/legal continuity.
     * Reversible via restoreCompany.
     */
    public function archiveCompany(Request $request, Company $company, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $actor = $request->user();
        if (! $this->adminAccessService->isSuperAdmin($actor)) {
            return response()->json([
                'success' => false,
                'message' => 'Super-admin permission required to archive a company.',
            ], 403);
        }

        if ($company->archived_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Company is already archived.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $service->archiveCompany($company, $actor, $validated['reason']);

        return response()->json([
            'success' => true,
            'message' => 'Company archived.',
            'data' => ['id' => $company->id],
        ]);
    }

    /**
     * Phase 7.2 — Restore archived company (super-admin only).
     */
    public function restoreCompany(Request $request, Company $company, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $actor = $request->user();
        if (! $this->adminAccessService->isSuperAdmin($actor)) {
            return response()->json([
                'success' => false,
                'message' => 'Super-admin permission required to restore a company.',
            ], 403);
        }

        if ($company->archived_at === null) {
            return response()->json([
                'success' => false,
                'message' => 'Company is not archived.',
            ], 422);
        }

        $service->restoreCompany($company, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Company restored.',
            'data' => ['id' => $company->id],
        ]);
    }

    /**
     * Phase 7.1 — "Ամբողջությամբ ջնջել" (super-admin only).
     * Anonymises retained-table PII, then force-deletes the user row.
     * Used for GDPR Right-to-Erasure (Article 17) requests.
     */
    public function hardDeleteUser(Request $request, int $id, AccountDeletionService $deletionService): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $actor = $request->user();
        if (! $this->adminAccessService->isSuperAdmin($actor)) {
            return response()->json([
                'success' => false,
                'message' => 'Super-admin permission required for hard delete.',
            ], 403);
        }

        $user = User::query()->findOrFail($id);

        if ((int) $actor->id === (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot hard-delete yourself.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $deletionService->adminHardDelete($user, $actor, $validated['reason']);

        return response()->json([
            'success' => true,
            'message' => 'User hard-deleted.',
        ]);
    }

    /**
     * Phase Բ.3 — bulk resend email-verification reminders.
     *
     * Accepts { ids: int[] }. Skips users that already verified their email.
     * Mail failures never abort the batch (logged-and-skipped) so one bad
     * address can't block reminders to the rest.
     */
    public function bulkRemindUnverifiedUsers(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $users = User::query()->whereIn('id', $validated['ids'])->get();
        $reminded = 0;
        $skipped = 0;
        foreach ($users as $user) {
            if ($user->hasVerifiedEmail()) {
                $skipped++;

                continue;
            }
            try {
                $user->sendEmailVerificationNotification();
                $reminded++;
            } catch (\Throwable $e) {
                // A single bad address must not break the batch.
                $skipped++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Reminded {$reminded} user(s); skipped {$skipped}.",
            'data' => ['reminded' => $reminded, 'skipped' => $skipped],
        ]);
    }

    /**
     * Phase Բ.3 — bulk-delete (anonymize) users.
     *
     * Uses the same reversible-safe AccountDeletionService::adminAnonymize as
     * the single-user action — bulk paths NEVER hard-delete. The actor's own
     * id is skipped defensively.
     */
    public function bulkDeleteUsers(Request $request, AccountDeletionService $deletionService): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $actor = $request->user();
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $users = User::query()->whereIn('id', $validated['ids'])->get();
        $processed = 0;
        $skipped = 0;
        foreach ($users as $user) {
            if ((int) $actor->id === (int) $user->id) {
                $skipped++;

                continue;
            }
            $deletionService->adminAnonymize(
                $user,
                $actor,
                $validated['reason'] ?? 'Bulk cleanup of unverified accounts'
            );
            $processed++;
        }

        return response()->json([
            'success' => true,
            'message' => "Anonymized {$processed} user(s); skipped {$skipped}.",
            'data' => ['processed' => $processed, 'skipped' => $skipped],
        ]);
    }

    // ─── Seller Applications ──────────────────────────────────────

    public function listSellerApplications(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $status = $request->filled('status') ? (string) $request->query('status') : null;

        $query = CompanySellerApplication::query()->with('company')->orderByDesc('id');
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', [
                CompanySellerApplication::STATUS_PENDING,
                CompanySellerApplication::STATUS_UNDER_REVIEW,
            ]);
        }

        // Tenant scope: non-super callers see only their own company's seller
        // applications. Empty company list → [0] → no rows.
        $user = $request->user();
        if ($user !== null && ! $this->adminAccessService->isSuperAdmin($user)) {
            $query->whereIn('company_id', $this->adminAccessService->visibleCompanyIds($user) ?: [0]);
        }

        $paginator = $query->paginate($this->commerceListPerPage($request));

        $data = $paginator->getCollection()->map(fn (CompanySellerApplication $a): array => $this->sellerApplicationToAdminRow($a))->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * Seller-application DETAIL — lets the admin open a single application to see
     * who applied, for which service, and the review trail (the list row alone
     * was not openable). Super sees any; a scoped admin only their own company's.
     */
    public function showSellerApplication(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $application = CompanySellerApplication::query()
            ->with(['company:id,name,type,country,city', 'reviewer:id,name,email'])
            ->findOrFail($id);

        // Tenant scope: a non-super caller may only open their own company's application.
        $user = $request->user();
        if ($user !== null && ! $this->adminAccessService->isSuperAdmin($user)) {
            $visible = $this->adminAccessService->visibleCompanyIds($user) ?: [0];
            if (! in_array((int) $application->company_id, $visible, true)) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }
        }

        $row = $this->sellerApplicationToAdminRow($application);
        $row['company'] = $application->company ? [
            'id' => $application->company->id,
            'name' => $application->company->name,
            'type' => $application->company->type,
            'country' => $application->company->country,
            'city' => $application->company->city,
        ] : null;
        $row['reviewer'] = $application->reviewer ? [
            'id' => $application->reviewer->id,
            'name' => $application->reviewer->name,
            'email' => $application->reviewer->email,
        ] : null;

        return response()->json([
            'success' => true,
            'data' => $row,
        ]);
    }

    public function approveSellerApplication(Request $request, int $id, SellerApplicationService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $application = CompanySellerApplication::query()->findOrFail($id);
        $fresh = $service->approve($application, $request->user()->id, $validated['notes'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Application approved.',
            'data' => [
                'message' => 'Application approved.',
                'application' => $this->sellerApplicationToAdminRow($fresh->loadMissing('company')),
            ],
        ]);
    }

    /**
     * Phase 8.6 — reject reason is now optional (was required:string).
     * The admin UI rejects low-info applications without a written reason
     * when the operator has out-of-band context.
     */
    public function rejectSellerApplication(Request $request, int $id, SellerApplicationService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $application = CompanySellerApplication::query()->findOrFail($id);
        $fresh = $service->reject($application, $request->user()->id, $validated['rejection_reason'] ?? '');

        return response()->json([
            'success' => true,
            'message' => 'Application rejected.',
            'data' => [
                'message' => 'Application rejected.',
                'application' => $this->sellerApplicationToAdminRow($fresh->loadMissing('company')),
            ],
        ]);
    }

    public function deactivatePackage(Request $request, Package $package, PlatformAdminService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $fresh = $service->forceDeactivatePackage(
            $package,
            $request->user(),
            $validated['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => PackageResource::make($fresh)->toArray($request),
        ]);
    }

    /**
     * GET — list homepage-feature rows for one package.
     */
    public function listPackageHomepageFeatures(Request $request, Package $package): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $rows = PackageHomepageFeature::query()
            ->where('package_id', $package->id)
            ->orderBy('section_slug')
            ->orderBy('position')
            ->get(['section_slug', 'position', 'is_active'])
            ->map(fn ($r) => [
                'section_slug' => $r->section_slug,
                'position' => (int) $r->position,
                'is_active' => (bool) $r->is_active,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'package_id' => $package->id,
                'sections' => PackageHomepageFeature::SECTIONS,
                'features' => $rows,
            ],
        ]);
    }

    /**
     * PUT — replace homepage-feature rows for this package.
     * Body: { features: [{ section_slug, position, is_active }, ...] }
     * Each (package_id, section_slug) pair is unique; the body fully
     * replaces existing rows for this package.
     */
    public function syncPackageHomepageFeatures(Request $request, Package $package): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'features' => ['present', 'array'],
            'features.*.section_slug' => ['required', 'string', Rule::in(PackageHomepageFeature::SECTIONS)],
            'features.*.position' => ['nullable', 'integer', 'min:0'],
            'features.*.is_active' => ['required', 'boolean'],
        ]);

        $incoming = collect($validated['features'])->keyBy('section_slug');
        $existing = PackageHomepageFeature::query()
            ->where('package_id', $package->id)
            ->get()
            ->keyBy('section_slug');

        // Upsert / delete per section
        foreach (PackageHomepageFeature::SECTIONS as $section) {
            $payload = $incoming->get($section);
            if (! $payload) {
                if ($existing->has($section)) {
                    $existing->get($section)->delete();
                }

                continue;
            }

            PackageHomepageFeature::query()->updateOrCreate(
                ['package_id' => $package->id, 'section_slug' => $section],
                [
                    'position' => (int) ($payload['position'] ?? 0),
                    'is_active' => (bool) $payload['is_active'],
                ]
            );
        }

        return $this->listPackageHomepageFeatures($request, $package);
    }

    public function approveApplication(
        Request $request,
        int $id,
        CompanyApplicationApprovalService $companyApplicationApprovalService
    ): JsonResponse {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $application = CompanyApplication::query()->findOrFail($id);
        $result = $companyApplicationApprovalService->approve(
            $application,
            $request->user(),
            $validated['decision_notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Application approved. Company and user created.',
            'data' => [
                'company_id' => $result['company']->id,
                'user_id' => $result['user']->id,
                'message' => 'Application approved. Company and user created.',
            ],
        ]);
    }

    public function rejectApplication(
        Request $request,
        int $id,
        CompanyApplicationApprovalService $companyApplicationApprovalService
    ): JsonResponse {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $application = CompanyApplication::query()->findOrFail($id);
        $companyApplicationApprovalService->reject($application, $request->user(), $validated['rejection_reason']);

        return response()->json([
            'success' => true,
            'message' => 'Application rejected.',
            'data' => ['message' => 'Application rejected.'],
        ]);
    }

    public function getSettings(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        return response()->json([
            'success' => true,
            'data' => PlatformSetting::query()->orderBy('key')->get()->map(fn (PlatformSetting $s): array => $this->platformSettingToAdminRow($s))->values()->all(),
        ]);
    }

    public function updateSetting(Request $request, string $key, PlatformSettingsService $platformSettingsService): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'value' => ['required', 'string', 'max:500'],
        ]);

        $existing = PlatformSetting::query()->where('key', $key)->first();
        if ($existing === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $platformSettingsService->set($key, $validated['value']);

        $updated = PlatformSetting::query()->where('key', $key)->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'Setting updated.',
            'data' => $this->platformSettingToAdminRow($updated),
        ]);
    }

    public function listAllReviews(Request $request, ReviewService $service): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        // Tenant scope (interim): reviews target a polymorphic entity (hotel /
        // offer / package) with no direct company_id, so proper "reviews of my
        // items" scoping needs a per-type join — done in Phase 2 with the
        // shared resolver. Until then, non-super callers get an empty list
        // (no cross-tenant leak) rather than every company's reviews.
        $caller = $request->user();
        if ($caller !== null && ! $this->adminAccessService->isSuperAdmin($caller)) {
            return response()->json(['success' => true, 'data' => [], 'meta' => [
                'current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 20,
            ]]);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(Review::STATUSES)],
            'entity_type' => ['nullable', 'string', Rule::in(Review::TARGET_ENTITY_TYPES)],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = max(1, min((int) ($validated['per_page'] ?? 20), 100));
        $filters = [];
        if (! empty($validated['status'])) {
            $filters['status'] = $validated['status'];
        }
        if (! empty($validated['entity_type'])) {
            $filters['entity_type'] = $validated['entity_type'];
        }
        if ($request->filled('user_id')) {
            $filters['user_id'] = (int) $validated['user_id'];
        }

        $paginator = $service->listAllForAdmin($filters, $perPage);

        $data = $paginator->getCollection()->map(fn (Review $review): array => $this->platformAdminReviewRow($review))->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function platformAdminUserRow(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            // Phase 4C+ (2026-05-31, fixed 2026-05-31) — surface bookings_count
            // for the Directory→People B2C customers column. The relation name
            // is `bookings()` so withCount produces `bookings_count` on the
            // model. Falls back to 0 when the controller didn't load the
            // withCount (e.g. /show endpoint).
            'bookings_count' => (int) ($user->bookings_count ?? 0),
            'companies' => $user->companies->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'role' => $c->pivot->role_id
                    ? (Role::find($c->pivot->role_id)?->name ?? 'unknown')
                    : 'unknown',
            ])->values()->all(),
        ];
    }

    /**
     * Full user detail for the admin user edit page — adds the personal
     * information fields surfaced by the Zulu_10 customer profile so admins
     * can audit / amend the same data.
     */
    private function platformAdminUserDetail(User $user): array
    {
        return array_merge($this->platformAdminUserRow($user), [
            'phone' => $user->phone,
            'preferred_language' => $user->preferred_language,
            'avatar' => $user->avatar,
            'birth_date' => $user->birth_date?->format('Y-m-d'),
            'nationality' => $user->nationality,
            'is_super_admin' => (bool) $user->is_super_admin,
            // Directory detail (2026-06-03): account state for the People detail
            // views (B2C customer / Staff / Unverified).
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'intended_role' => $user->intended_role,
            'two_factor_method' => $user->two_factor_method,
            'two_factor_required' => (bool) $user->two_factor_required,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sellerApplicationToAdminRow(CompanySellerApplication $a): array
    {
        return [
            'id' => $a->id,
            'company_id' => $a->company_id,
            'company_name' => $a->company?->name,
            'service_type' => $a->service_type,
            'status' => $a->status,
            'rejection_reason' => $a->rejection_reason,
            'notes' => $a->notes,
            'applied_at' => $a->applied_at?->toIso8601String(),
            'reviewed_at' => $a->reviewed_at?->toIso8601String(),
            'reviewed_by' => $a->reviewed_by,
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function platformSettingToAdminRow(PlatformSetting $s): array
    {
        return [
            'id' => $s->id,
            'key' => $s->key,
            'value' => $s->value,
            'type' => $s->type,
            'description' => $s->description,
            'created_at' => $s->created_at?->toIso8601String(),
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function platformAdminReviewRow(Review $review): array
    {
        $row = [
            'id' => $review->id,
            'rating' => $review->rating,
            'review_text' => $review->review_text,
            'status' => $review->status,
            'target_entity_type' => $review->target_entity_type,
            'target_entity_id' => $review->target_entity_id,
            'moderation_notes' => $review->moderation_notes,
            'created_at' => $review->created_at?->toIso8601String(),
        ];
        if ($review->relationLoaded('user') && $review->user !== null) {
            $row['user'] = ['id' => $review->user->id, 'name' => $review->user->name];
        }

        return $row;
    }

    private function denyUnlessPlatformAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        // Phase 6: the route-group middleware is the deny-by-default gate
        // (operators reach only allowlisted GET reads). This helper re-confirms
        // admin-panel access; data is scoped by visibleCompanyIds downstream.
        if ($user === null || ! $this->adminAccessService->canAccessAdminPanel($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    private function parseOptionalBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        $v = strtolower((string) $value);
        if (in_array($v, ['1', 'true'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false'], true)) {
            return false;
        }

        return null;
    }

    private function paginatedPaymentsResponse(Request $request, LengthAwarePaginator $paginator): JsonResponse
    {
        $data = $paginator->getCollection()->map(function (Payment $payment): array {
            $row = [
                'id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'payment_method' => $payment->payment_method,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'reference_code' => $payment->reference_code,
                'created_at' => $payment->created_at?->toIso8601String(),
            ];
            if ($payment->relationLoaded('invoice') && $payment->invoice !== null) {
                $row['invoice'] = [
                    'id' => $payment->invoice->id,
                    'total_amount' => (float) $payment->invoice->total_amount,
                    'status' => $payment->invoice->status,
                    'unique_booking_reference' => $payment->invoice->unique_booking_reference,
                ];
                // Finance group v2 — surface order.company on the payment row
                // so the Payments table can render an avatar per row without
                // a second round-trip.
                if ($payment->invoice->relationLoaded('order') && $payment->invoice->order !== null) {
                    $order = $payment->invoice->order;
                    $row['invoice']['order_id'] = $order->id;
                    if ($order->relationLoaded('company') && $order->company !== null) {
                        $row['invoice']['company'] = [
                            'id' => $order->company->id,
                            'name' => $order->company->name,
                        ];
                    }
                    if ($order->relationLoaded('agentCompany') && $order->agentCompany !== null) {
                        $row['invoice']['agent_company'] = [
                            'id' => $order->agentCompany->id,
                            'name' => $order->agentCompany->name,
                        ];
                    }
                }
            }

            return $row;
        })->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }
}

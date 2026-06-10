<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CrmActivity;
use App\Models\CrmCompanySetting;
use App\Models\CrmDeal;
use App\Models\CrmEmployeeCompensation;
use App\Models\Order;
use App\Models\User;
use App\Models\UserCompany;
use App\Services\Admin\AdminAccessService;
use App\Services\Admin\CompanyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * CRM — deals (sales pipeline) + activities (interaction log).
 *
 * Tenancy: super-admins see every company's records; everyone else is scoped
 * to the companies they belong to (user_company pivot). Super-admin detection
 * is wrapped in a defensive helper so a model-shape mismatch can never 500 the
 * endpoint — worst case it falls back to the (safer) company-scoped view.
 *
 * Phase 3 Layer-B: within those companies, a plain employee sees only the
 * deals/activities they own (owner_user_id stamped on create); the company
 * owner or a holder of crm.view_all sees the whole company.
 *
 * Response envelope matches the rest of platform-admin: {success,data,meta}.
 */
class CrmController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private CompanyAccessService $companyAccessService,
    ) {}

    /**
     * Within-company row scope (Layer B): the user id to filter owner_user_id
     * by, or null for "whole company" (owner / crm.view_all / super-admin).
     * Defensive like scopeCompanyIds — any resolver hiccup falls back to the
     * company-scoped (broader but still tenant-safe) view, never a 500.
     */
    private function rowScopeUserId(Request $request): ?int
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }
        try {
            if ($this->isSuperAdmin($user)) {
                return null;
            }

            return $this->adminAccessService->employeeRowScopeUserId($user, 'crm.view_all');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Company ids the caller may see, or null for "all" (super-admin). */
    private function scopeCompanyIds(Request $request): ?array
    {
        $user = $request->user();
        if ($user === null) {
            return [0]; // unauthenticated guard — match nothing
        }
        if ($this->isSuperAdmin($user)) {
            return null; // no scope = all companies
        }
        try {
            $ids = $user->companies()->pluck('companies.id')->all();
        } catch (\Throwable $e) {
            $ids = [];
        }

        return $ids ?: [0];
    }

    private function isSuperAdmin($user): bool
    {
        // Delegate to the canonical resolver so CRM scoping matches the rest
        // of the admin panel (recognises the super_admin role + super perms);
        // keep the defensive try/catch so a model hiccup never 500s a CRM read.
        try {
            return $user instanceof User && $this->adminAccessService->isSuperAdmin($user);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function ownerCompanyId(Request $request): ?int
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }
        try {
            return $user->companies()->value('companies.id');
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ─── Deals ────────────────────────────────────────────────────────────

    public function listDeals(Request $request): JsonResponse
    {
        $companyIds = $this->scopeCompanyIds($request);
        $perPage = (int) ($request->query('per_page', 50));
        $perPage = max(1, min($perPage, 200));

        $query = CrmDeal::query()->with(['customer:id,name,email', 'owner:id,name']);
        if ($companyIds !== null) {
            $query->whereIn('company_id', $companyIds);
        }
        // Phase 3 Layer-B: plain employee → own deals only.
        $rowScopeUserId = $this->rowScopeUserId($request);
        if ($rowScopeUserId !== null) {
            $query->where('owner_user_id', $rowScopeUserId);
        }
        if ($stage = $request->query('stage')) {
            $query->where('stage', $stage);
        }
        if ($ownerId = $request->query('owner_user_id')) {
            $query->where('owner_user_id', (int) $ownerId);
        }
        if ($search = $request->query('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        $deals = $query->orderByDesc('updated_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => array_map([$this, 'dealArray'], $deals->items()),
            'meta' => [
                'current_page' => $deals->currentPage(),
                'last_page' => $deals->lastPage(),
                'total' => $deals->total(),
                'per_page' => $deals->perPage(),
            ],
        ]);
    }

    public function storeDeal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'customer_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'value_amount' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'max:3'],
            'service_type' => ['nullable', 'string', 'max:50'],
            'stage' => ['nullable', 'string', 'in:'.implode(',', CrmDeal::STAGES)],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'source' => ['nullable', 'string', 'max:50'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $data['company_id'] = $request->input('company_id', $this->ownerCompanyId($request));
        $data['stage'] = $data['stage'] ?? 'new';
        $data['owner_user_id'] = $data['owner_user_id'] ?? optional($request->user())->id;

        $deal = CrmDeal::create($data);

        return response()->json(['success' => true, 'data' => $this->dealArray($deal->fresh(['customer:id,name,email', 'owner:id,name']))], 201);
    }

    public function updateDeal(Request $request, CrmDeal $deal): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'customer_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'value_amount' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'max:3'],
            'service_type' => ['nullable', 'string', 'max:50'],
            'stage' => ['nullable', 'string', 'in:'.implode(',', CrmDeal::STAGES)],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'source' => ['nullable', 'string', 'max:50'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $deal->update($data);

        return response()->json(['success' => true, 'data' => $this->dealArray($deal->fresh(['customer:id,name,email', 'owner:id,name']))]);
    }

    public function destroyDeal(CrmDeal $deal): JsonResponse
    {
        $deal->delete();

        return response()->json(['success' => true]);
    }

    // ─── Activities ─────────────────────────────────────────────────────────

    public function listActivities(Request $request): JsonResponse
    {
        $companyIds = $this->scopeCompanyIds($request);
        $perPage = (int) ($request->query('per_page', 50));
        $perPage = max(1, min($perPage, 200));

        $query = CrmActivity::query()->with(['owner:id,name']);
        if ($companyIds !== null) {
            $query->whereIn('company_id', $companyIds);
        }
        // Phase 3 Layer-B: plain employee → own activities only.
        $rowScopeUserId = $this->rowScopeUserId($request);
        if ($rowScopeUserId !== null) {
            $query->where('owner_user_id', $rowScopeUserId);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        // Filter to one subject (e.g. a customer card's Communication tab).
        if (($subjectType = $request->query('subject_type')) && ($subjectId = $request->query('subject_id'))) {
            $query->where('subject_type', $subjectType)->where('subject_id', (int) $subjectId);
        }

        $rows = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => array_map([$this, 'activityArray'], $rows->items()),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
            ],
        ]);
    }

    public function storeActivity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', CrmActivity::TYPES)],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'subject_type' => ['nullable', 'string', 'in:'.implode(',', CrmActivity::SUBJECT_TYPES)],
            'subject_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:'.implode(',', CrmActivity::STATUSES)],
            'due_at' => ['nullable', 'date'],
        ]);

        $data['company_id'] = $request->input('company_id', $this->ownerCompanyId($request));
        $data['owner_user_id'] = optional($request->user())->id;
        $data['status'] = $data['status'] ?? 'open';

        $activity = CrmActivity::create($data);

        return response()->json(['success' => true, 'data' => $this->activityArray($activity->fresh(['owner:id,name']))], 201);
    }

    public function updateActivity(Request $request, CrmActivity $activity): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['sometimes', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', CrmActivity::STATUSES)],
            'due_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
        ]);

        if (($data['status'] ?? null) === 'done' && empty($activity->completed_at) && empty($data['completed_at'])) {
            $data['completed_at'] = now();
        }

        $activity->update($data);

        return response()->json(['success' => true, 'data' => $this->activityArray($activity->fresh(['owner:id,name']))]);
    }

    // ─── Customers (the company's own buyers) ───────────────────────────────

    /**
     * CRM Customers = the people who bought from THIS company (order.user_id on
     * orders whose seller/agent company is in the caller's scope). NOT the
     * platform B2C registry — an operator sees only their own buyers; super
     * sees every buyer. Shape matches the frontend CustomerRow
     * {id,name,email,status,bookings_count} where bookings_count is the
     * customer's order count WITH this company.
     */
    public function customers(Request $request): JsonResponse
    {
        $companyIds = $this->scopeCompanyIds($request);

        // Constrains "bookings" (orders placed by the user) to the caller's
        // companies — as seller (company_id) or referring agent
        // (agent_company_id). null scope = super = no company filter.
        $orderScope = function ($q) use ($companyIds): void {
            if ($companyIds !== null) {
                $q->where(function ($w) use ($companyIds): void {
                    $w->whereIn('company_id', $companyIds)
                        ->orWhereIn('agent_company_id', $companyIds);
                });
            }
        };

        $perPage = max(1, min((int) $request->query('per_page', 25), 200));

        $query = User::query()
            ->whereHas('bookings', $orderScope)
            ->withCount(['bookings as bookings_count' => $orderScope]);

        if (is_string($search = $request->query('search')) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%");
            });
        }
        if (is_string($status = $request->query('status')) && trim($status) !== '') {
            $query->where('status', $status);
        }

        $page = $query->orderByDesc('id')->paginate($perPage);

        $data = collect($page->items())->map(fn (User $u): array => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'status' => $u->status,
            'bookings_count' => (int) ($u->bookings_count ?? 0),
        ])->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /**
     * Full-dataset stat cards for CRM → Customers, scoped IDENTICALLY to
     * customers(). The customer set = the caller's OWN buyers (users with >=1
     * order where the caller's company is the seller or referring agent). super
     * = no company filter. Returned over the WHOLE scoped set, not one page:
     *   active           = scoped buyers whose account status is 'active'
     *   with_bookings    = scoped buyers with >=1 order (the whole set, since a
     *                      buyer is DEFINED by having a scoped order)
     *   new_this_month   = scoped buyers created in the current calendar month
     */
    public function customersStats(Request $request): JsonResponse
    {
        $companyIds = $this->scopeCompanyIds($request);

        $orderScope = function ($q) use ($companyIds): void {
            if ($companyIds !== null) {
                $q->where(function ($w) use ($companyIds): void {
                    $w->whereIn('company_id', $companyIds)
                        ->orWhereIn('agent_company_id', $companyIds);
                });
            }
        };

        $base = fn () => User::query()->whereHas('bookings', $orderScope);

        $monthStart = Carbon::now()->startOfMonth();

        return response()->json([
            'success' => true,
            'data' => [
                'active' => (int) $base()->where('status', User::STATUS_ACTIVE)->count(),
                'with_bookings' => (int) $base()->count(),
                'new_this_month' => (int) $base()->where('created_at', '>=', $monthStart)->count(),
            ],
        ]);
    }

    // ─── Stats (Pipeline + Team feed) ───────────────────────────────────────

    public function stats(Request $request): JsonResponse
    {
        $companyIds = $this->scopeCompanyIds($request);

        $dealQuery = CrmDeal::query();
        if ($companyIds !== null) {
            $dealQuery->whereIn('company_id', $companyIds);
        }
        $open = (clone $dealQuery)->whereNotIn('stage', ['won', 'lost']);

        return response()->json([
            'success' => true,
            'data' => [
                'open_deals' => (clone $open)->count(),
                'pipeline_value' => (float) (clone $open)->sum('value_amount'),
                'won_deals' => (clone $dealQuery)->where('stage', 'won')->count(),
                'by_stage' => (clone $dealQuery)
                    ->selectRaw('stage, count(*) as count, coalesce(sum(value_amount),0) as value')
                    ->groupBy('stage')
                    ->get()
                    ->keyBy('stage'),
            ],
        ]);
    }

    // ─── Serialisers ────────────────────────────────────────────────────────

    private function dealArray(CrmDeal $d): array
    {
        return [
            'id' => $d->id,
            'title' => $d->title,
            'value_amount' => (float) $d->value_amount,
            'currency' => $d->currency,
            'service_type' => $d->service_type,
            'stage' => $d->stage,
            'probability' => $d->probability,
            'source' => $d->source,
            'expected_close_date' => optional($d->expected_close_date)->toDateString(),
            'company_id' => $d->company_id,
            'customer' => $d->customer ? ['id' => $d->customer->id, 'name' => $d->customer->name, 'email' => $d->customer->email] : null,
            'owner' => $d->owner ? ['id' => $d->owner->id, 'name' => $d->owner->name] : null,
            'created_at' => optional($d->created_at)->toIso8601String(),
            'updated_at' => optional($d->updated_at)->toIso8601String(),
        ];
    }

    private function activityArray(CrmActivity $a): array
    {
        return [
            'id' => $a->id,
            'type' => $a->type,
            'subject' => $a->subject,
            'body' => $a->body,
            'subject_type' => $a->subject_type,
            'subject_id' => $a->subject_id,
            'status' => $a->status,
            'due_at' => optional($a->due_at)->toIso8601String(),
            'completed_at' => optional($a->completed_at)->toIso8601String(),
            'company_id' => $a->company_id,
            'owner' => $a->owner ? ['id' => $a->owner->id, 'name' => $a->owner->name] : null,
            'created_at' => optional($a->created_at)->toIso8601String(),
        ];
    }

    // ─── Team (per-employee sales + payroll) ─────────────────────────────

    /** Resolve which company's team to show: explicit ?company_id, else caller's first. */
    private function teamCompanyId(Request $request): ?int
    {
        $explicit = $request->query('company_id');
        if ($explicit !== null && $explicit !== '') {
            return (int) $explicit;
        }

        return $this->ownerCompanyId($request);
    }

    /**
     * Team sales leaderboard + computed pay for one company + month.
     * Per employee: attributed order revenue (sold_by_user_id) grouped by
     * currency for the period, won-deal count, and pay computed from their
     * compensation config.
     */
    public function team(Request $request): JsonResponse
    {
        $companyId = $this->teamCompanyId($request);
        if ($companyId === null) {
            return response()->json(['success' => true, 'data' => [], 'meta' => ['company_id' => null]]);
        }

        // Period: default to the current calendar month (YYYY-MM via ?month).
        $month = (string) $request->query('month', now()->format('Y-m'));
        try {
            $start = Carbon::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00')->startOfMonth();
        } catch (\Throwable $e) {
            $start = now()->startOfMonth();
        }
        $end = (clone $start)->endOfMonth();

        $company = Company::query()->with(['users:id,name,email,status'])->find($companyId);
        if ($company === null) {
            return response()->json(['success' => false, 'message' => 'Company not found'], 404);
        }

        // §7 — role per member (drives the Team pane's action-button gating).
        $roleNames = UserCompany::query()
            ->where('company_id', $companyId)
            ->join('roles', 'user_company.role_id', '=', 'roles.id')
            ->pluck('roles.name', 'user_company.user_id');

        $comp = CrmEmployeeCompensation::query()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('user_id');

        // Which order statuses count as a sale is a per-company setting (the
        // CRM Options page). Defaults to paid+confirmed when not customised.
        $countStatuses = $this->salesCountStatusesFor($companyId);

        $rows = [];
        foreach ($company->users as $emp) {
            // Attribution model (Arshak's decision): a sale counts for an
            // employee when their DEAL is marked "won". So revenue = the value
            // of the deals this employee won in the period, grouped by currency.
            // (The order-based path + the Options sales_count_statuses gate
            // still exist for direct bookings; see $countStatuses below.)
            $revenueByCurrency = CrmDeal::query()
                ->where('owner_user_id', $emp->id)
                ->where('stage', 'won')
                ->whereBetween('updated_at', [$start, $end])
                ->selectRaw('currency, count(*) as orders_count, coalesce(sum(value_amount),0) as revenue')
                ->groupBy('currency')
                ->get();

            $wonDeals = (int) $revenueByCurrency->sum('orders_count');
            $ordersCount = $wonDeals;
            // Informational: direct bookings attributed to this employee that
            // also reached a counted status (the order path; usually 0 until a
            // booking flow stamps sold_by_user_id).
            $directOrders = Order::query()
                ->where('sold_by_user_id', $emp->id)
                ->whereIn('status', $countStatuses)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $cfg = $comp->get($emp->id);
            $payCurrency = $cfg?->currency ?? 'USD';
            $revenueInPayCurrency = (float) ($revenueByCurrency->firstWhere('currency', $payCurrency)->revenue ?? 0);
            $computedPay = $cfg ? $cfg->computePay($revenueInPayCurrency) : null;

            $rows[] = [
                'user' => [
                    'id' => $emp->id,
                    'name' => $emp->name,
                    'email' => $emp->email,
                    'status' => $emp->status,
                    'role_name' => $roleNames[$emp->id] ?? null,
                ],
                'orders_count' => $ordersCount,
                'won_deals' => $wonDeals,
                'direct_orders' => $directOrders,
                'revenue_by_currency' => $revenueByCurrency->map(fn ($r) => [
                    'currency' => $r->currency,
                    'orders_count' => (int) $r->orders_count,
                    'revenue' => (float) $r->revenue,
                ])->values(),
                'compensation' => $cfg ? $this->compensationArray($cfg) : null,
                'computed_pay' => $computedPay,
                'pay_currency' => $payCurrency,
            ];
        }

        // Highest revenue (in their pay currency) first — the leaderboard order.
        usort($rows, fn ($a, $b) => ($b['computed_pay'] ?? 0) <=> ($a['computed_pay'] ?? 0));

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'company_id' => $companyId,
                'month' => $start->format('Y-m'),
                'sales_count_statuses' => $countStatuses,
            ],
        ]);
    }

    // ─── CRM Options (per-company settings) ──────────────────────────────

    /** Resolve the counted-sale statuses for a company (with default). */
    private function salesCountStatusesFor(int $companyId): array
    {
        $row = CrmCompanySetting::query()->where('company_id', $companyId)->first();

        return $row
            ? $row->salesCountStatuses()
            : CrmCompanySetting::DEFAULT_SALES_STATUSES;
    }

    public function getSettings(Request $request): JsonResponse
    {
        $companyId = $this->teamCompanyId($request);
        if ($companyId === null) {
            return response()->json([
                'success' => true,
                'data' => [
                    'company_id' => null,
                    'sales_count_statuses' => CrmCompanySetting::DEFAULT_SALES_STATUSES,
                    'sales_status_options' => CrmCompanySetting::SALES_STATUS_OPTIONS,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'company_id' => $companyId,
                'sales_count_statuses' => $this->salesCountStatusesFor($companyId),
                'sales_status_options' => CrmCompanySetting::SALES_STATUS_OPTIONS,
            ],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'sales_count_statuses' => ['required', 'array', 'min:1'],
            'sales_count_statuses.*' => ['string', 'in:'.implode(',', CrmCompanySetting::SALES_STATUS_OPTIONS)],
        ]);

        $companyId = (int) $data['company_id'];
        $row = CrmCompanySetting::query()->firstOrNew(['company_id' => $companyId]);
        $settings = $row->settings ?? [];
        $settings['sales_count_statuses'] = array_values(array_unique($data['sales_count_statuses']));
        $row->settings = $settings;
        $row->updated_by_user_id = optional($request->user())->id;
        $row->save();

        return response()->json([
            'success' => true,
            'data' => [
                'company_id' => $companyId,
                'sales_count_statuses' => $row->salesCountStatuses(),
                'sales_status_options' => CrmCompanySetting::SALES_STATUS_OPTIONS,
            ],
        ]);
    }

    public function setCompensation(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'model' => ['required', 'string', 'in:'.implode(',', CrmEmployeeCompensation::MODELS)],
            'base_amount' => ['nullable', 'numeric', 'min:0'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', 'string', 'max:3'],
            'notes' => ['nullable', 'string'],
        ]);

        $companyId = (int) $data['company_id'];
        $user = $request->user();

        // Authorisation gate. The platform-admin middleware now ADMITS a company
        // owner to this write, so the real ownership check lives here. Super /
        // platform staff keep their existing governance access; a tenant owner
        // (operator/agent) may set pay ONLY for an employee of a company they
        // manage. A plain employee, or a cross-company attempt, is refused.
        if (! $this->adminAccessService->isPlatformAdmin($user)) {
            $company = Company::query()->find($companyId);
            if ($company === null
                || ! $this->companyAccessService->canManageCompany($user, $company)) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
            if (! $company->users()->whereKey($userId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee is not a member of this company',
                ], 422);
            }
        }

        $cfg = CrmEmployeeCompensation::updateOrCreate(
            ['user_id' => $userId, 'company_id' => $companyId],
            [
                'model' => $data['model'],
                'base_amount' => $data['base_amount'] ?? 0,
                'commission_percent' => $data['commission_percent'] ?? 0,
                'currency' => $data['currency'] ?? 'USD',
                'notes' => $data['notes'] ?? null,
                'updated_by_user_id' => optional($request->user())->id,
            ],
        );

        return response()->json(['success' => true, 'data' => $this->compensationArray($cfg->fresh())]);
    }

    private function compensationArray(CrmEmployeeCompensation $c): array
    {
        return [
            'id' => $c->id,
            'user_id' => $c->user_id,
            'company_id' => $c->company_id,
            'model' => $c->model,
            'base_amount' => (float) $c->base_amount,
            'commission_percent' => (float) $c->commission_percent,
            'currency' => $c->currency,
            'notes' => $c->notes,
        ];
    }
}

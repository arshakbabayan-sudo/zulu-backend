<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrmActivity;
use App\Models\CrmDeal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRM — deals (sales pipeline) + activities (interaction log).
 *
 * Tenancy: super-admins see every company's records; everyone else is scoped
 * to the companies they belong to (user_company pivot). Super-admin detection
 * is wrapped in a defensive helper so a model-shape mismatch can never 500 the
 * endpoint — worst case it falls back to the (safer) company-scoped view.
 *
 * Response envelope matches the rest of platform-admin: {success,data,meta}.
 */
class CrmController extends Controller
{
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
        try {
            if (isset($user->is_super_admin) && $user->is_super_admin) {
                return true;
            }
            if (method_exists($user, 'isSuperAdmin')) {
                return (bool) $user->isSuperAdmin();
            }
        } catch (\Throwable $e) {
            // fall through to false
        }
        return false;
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
            'stage' => ['nullable', 'string', 'in:' . implode(',', CrmDeal::STAGES)],
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
            'stage' => ['nullable', 'string', 'in:' . implode(',', CrmDeal::STAGES)],
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
            'type' => ['required', 'string', 'in:' . implode(',', CrmActivity::TYPES)],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'subject_type' => ['nullable', 'string', 'in:' . implode(',', CrmActivity::SUBJECT_TYPES)],
            'subject_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:' . implode(',', CrmActivity::STATUSES)],
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
            'status' => ['nullable', 'string', 'in:' . implode(',', CrmActivity::STATUSES)],
            'due_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
        ]);

        if (($data['status'] ?? null) === 'done' && empty($activity->completed_at) && empty($data['completed_at'])) {
            $data['completed_at'] = now();
        }

        $activity->update($data);

        return response()->json(['success' => true, 'data' => $this->activityArray($activity->fresh(['owner:id,name']))]);
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
            $start = \Illuminate\Support\Carbon::createFromFormat('Y-m-d H:i:s', $month . '-01 00:00:00')->startOfMonth();
        } catch (\Throwable $e) {
            $start = now()->startOfMonth();
        }
        $end = (clone $start)->endOfMonth();

        $company = \App\Models\Company::query()->with(['users:id,name,email'])->find($companyId);
        if ($company === null) {
            return response()->json(['success' => false, 'message' => 'Company not found'], 404);
        }

        $comp = \App\Models\CrmEmployeeCompensation::query()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('user_id');

        $rows = [];
        foreach ($company->users as $emp) {
            // Attributed confirmed/completed revenue this month, grouped by currency.
            $revenueByCurrency = \App\Models\Order::query()
                ->where('sold_by_user_id', $emp->id)
                ->whereIn('status', [\App\Models\Order::STATUS_CONFIRMED, \App\Models\Order::STATUS_COMPLETED])
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw('currency, count(*) as orders_count, coalesce(sum(total_amount),0) as revenue')
                ->groupBy('currency')
                ->get();

            $ordersCount = (int) $revenueByCurrency->sum('orders_count');
            $wonDeals = \App\Models\CrmDeal::query()
                ->where('owner_user_id', $emp->id)
                ->where('stage', 'won')
                ->whereBetween('updated_at', [$start, $end])
                ->count();

            $cfg = $comp->get($emp->id);
            $payCurrency = $cfg?->currency ?? 'USD';
            $revenueInPayCurrency = (float) ($revenueByCurrency->firstWhere('currency', $payCurrency)->revenue ?? 0);
            $computedPay = $cfg ? $cfg->computePay($revenueInPayCurrency) : null;

            $rows[] = [
                'user' => ['id' => $emp->id, 'name' => $emp->name, 'email' => $emp->email],
                'orders_count' => $ordersCount,
                'won_deals' => $wonDeals,
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
            'meta' => ['company_id' => $companyId, 'month' => $start->format('Y-m')],
        ]);
    }

    public function setCompensation(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'model' => ['required', 'string', 'in:' . implode(',', \App\Models\CrmEmployeeCompensation::MODELS)],
            'base_amount' => ['nullable', 'numeric', 'min:0'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', 'string', 'max:3'],
            'notes' => ['nullable', 'string'],
        ]);

        $cfg = \App\Models\CrmEmployeeCompensation::updateOrCreate(
            ['user_id' => $userId, 'company_id' => $data['company_id']],
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

    private function compensationArray(\App\Models\CrmEmployeeCompensation $c): array
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

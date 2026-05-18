<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPlan;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 7.11 — subscription plans catalog + per-company assignment.
 *
 * Stripe / ArCa auto-renew wiring is parked per memory
 * `feedback_no_payment_integration_yet.md`. Period dates are admin-managed
 * for now.
 */
class SubscriptionsController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    public function listPlans(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $plans = SubscriptionPlan::query()
            ->when(! $this->adminAccessService->isSuperAdmin($user), fn ($q) => $q->where('is_active', true))
            ->orderBy('display_order')
            ->orderBy('monthly_price')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $plans->map(fn ($p) => $this->serializePlan($p))->values()->all(),
        ]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:subscription_plans,code'],
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:2000'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'annual_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['nullable', 'integer'],
        ]);

        if (isset($validated['currency'])) {
            $validated['currency'] = strtoupper($validated['currency']);
        }

        $plan = SubscriptionPlan::query()->create($validated);

        return response()->json(['success' => true, 'data' => $this->serializePlan($plan)], 201);
    }

    public function updatePlan(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }
        $plan = SubscriptionPlan::query()->find($id);
        if ($plan === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:2000'],
            'monthly_price' => ['sometimes', 'numeric', 'min:0'],
            'annual_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['nullable', 'integer'],
        ]);

        if (isset($validated['currency'])) {
            $validated['currency'] = strtoupper($validated['currency']);
        }

        $plan->fill($validated);
        $plan->save();

        return response()->json(['success' => true, 'data' => $this->serializePlan($plan)]);
    }

    public function listCompanySubscriptions(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $rows = CompanySubscription::query()
            ->with(['company:id,name', 'plan:id,code,name,monthly_price,currency'])
            ->orderByDesc('updated_at')
            ->paginate(min(200, max(1, (int) $request->query('per_page', 50))));

        return response()->json([
            'success' => true,
            'data' => $rows->getCollection()->map(fn ($r) => $this->serializeCompanySubscription($r))->values()->all(),
            'meta' => [
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    public function assignCompanySubscription(Request $request, int $companyId): JsonResponse
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'status' => ['sometimes', Rule::in(CompanySubscription::STATUSES)],
            'billing_period' => ['sometimes', Rule::in(CompanySubscription::BILLING_PERIODS)],
            'period_starts_at' => ['nullable', 'date'],
            'period_ends_at' => ['nullable', 'date', 'after_or_equal:period_starts_at'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = CompanySubscription::query()->updateOrCreate(
            ['company_id' => $companyId],
            array_merge($validated, ['assigned_by_user_id' => $request->user()->id])
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializeCompanySubscription($row->fresh(['company', 'plan'])),
        ]);
    }

    private function denyUnlessSuperAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function serializePlan(SubscriptionPlan $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'description' => $p->description,
            'monthly_price' => (float) $p->monthly_price,
            'annual_price' => $p->annual_price !== null ? (float) $p->annual_price : null,
            'currency' => $p->currency,
            'features' => $p->features,
            'is_active' => (bool) $p->is_active,
            'display_order' => (int) $p->display_order,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeCompanySubscription(CompanySubscription $r): array
    {
        return [
            'id' => $r->id,
            'company' => $r->company ? ['id' => $r->company->id, 'name' => $r->company->name] : null,
            'plan' => $r->plan
                ? ['id' => $r->plan->id, 'code' => $r->plan->code, 'name' => $r->plan->name, 'monthly_price' => (float) $r->plan->monthly_price, 'currency' => $r->plan->currency]
                : null,
            'status' => $r->status,
            'billing_period' => $r->billing_period,
            'period_starts_at' => $r->period_starts_at?->toIso8601String(),
            'period_ends_at' => $r->period_ends_at?->toIso8601String(),
            'notes' => $r->notes,
        ];
    }
}

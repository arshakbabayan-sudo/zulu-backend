<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Concerns\AuthorizesCommerceAccess;
use App\Http\Controllers\Controller;
use App\Models\CommissionRule;
use App\Models\CommissionTransaction;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Legacy adapter endpoint for pre-Sprint-12 admin UI.
 *
 * The old commissions list only exposed seller-scoped policies, so this adapter intentionally
 * narrows listing queries to CommissionRule rows where level='seller' and scope_id=company_id.
 * Global/category/partner_agreement rules remain intentionally hidden from this legacy URL set.
 */
class CommissionController extends Controller
{
    use AuthorizesCommerceAccess;
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'commissions.view');
        $query = CommissionRule::query()
            ->where('level', 'seller')
            ->whereIn('scope_id', $companyIds)
            ->orderBy('created_at');

        if (! $request->filled('page')) {
            $rules = $companyIds === [] ? collect() : $query->get();

            return response()->json([
                'success' => true,
                'data' => $rules->map(fn (CommissionRule $rule): array => $this->transformRuleToPolicyShape($rule))->values(),
            ]);
        }

        $paginator = $companyIds === []
            ? CommissionRule::query()->whereRaw('0 = 1')->paginate($this->commerceListPerPage($request))
            : $query->paginate($this->commerceListPerPage($request));

        return $this->paginatedLegacyResponse(
            $paginator,
            fn (CommissionRule $rule): array => $this->transformRuleToPolicyShape($rule)
        );
    }

    public function indexRecords(Request $request): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'commission_records.view');
        $query = CommissionTransaction::query()
            ->with('rule')
            ->whereIn('seller_id', $companyIds)
            ->orderByDesc('created_at');

        if (! $request->filled('page')) {
            $records = $companyIds === [] ? collect() : $query->get();

            return response()->json([
                'success' => true,
                'data' => $records->map(fn (CommissionTransaction $transaction): array => $this->transformTransactionToRecordShape($transaction))->values(),
            ]);
        }

        $paginator = $companyIds === []
            ? CommissionTransaction::query()->whereRaw('0 = 1')->paginate($this->commerceListPerPage($request))
            : $query->paginate($this->commerceListPerPage($request));

        return $this->paginatedLegacyResponse(
            $paginator,
            fn (CommissionTransaction $transaction): array => $this->transformTransactionToRecordShape($transaction)
        );
    }

    public function show(Request $request, string $ruleId): JsonResponse
    {
        $rule = CommissionRule::query()->findOrFail($ruleId);
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'commissions.view');

        if (! $this->canViewRule($request, $rule, $companyIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->transformRuleToPolicyShape($rule),
        ]);
    }

    /**
     * Phase 7.4 — create a seller-scoped commission rule from the admin UI.
     * Super-admin or anyone with `commissions.write` on the target company.
     */
    public function createPolicy(Request $request): JsonResponse
    {
        $actor = $request->user();
        if ($actor === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'service_type' => ['nullable', 'string', 'max:64'],
            'type' => ['required', 'string', 'in:percentage,fixed'],
            'percent' => ['required_if:type,percentage', 'nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_value' => ['required_if:type,fixed', 'nullable', 'numeric', 'min:0'],
            'fixed_currency' => ['nullable', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['nullable', 'string', 'in:active,inactive,scheduled'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $companyId = (int) $validated['company_id'];
        if (! $this->adminAccessService->isSuperAdmin($actor)) {
            $allowed = $this->adminAccessService->companyIdsForCommerceList($actor, 'commissions.write');
            if (! in_array($companyId, $allowed, true)) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }

        $rule = CommissionRule::query()->create([
            'type' => $validated['type'],
            'level' => 'seller',
            'scope_id' => $companyId,
            'service_type' => $validated['service_type'] ?? null,
            'percentage_value' => $validated['type'] === 'percentage' ? $validated['percent'] : null,
            'fixed_value' => $validated['type'] === 'fixed' ? $validated['fixed_value'] : null,
            'fixed_currency' => $validated['type'] === 'fixed' ? ($validated['fixed_currency'] ?? 'AMD') : null,
            'direction' => 'zulu_from_seller',
            'priority' => 0,
            'effective_from' => $validated['effective_from'] ?? now(),
            'effective_to' => $validated['effective_to'] ?? null,
            'status' => $validated['status'] ?? 'active',
            'active' => ($validated['status'] ?? 'active') === 'active',
            'notes' => $validated['notes'] ?? null,
            'created_by' => $actor->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->transformRuleToPolicyShape($rule),
        ], 201);
    }

    /**
     * Phase 7.4 — partial update on a commission rule.
     */
    public function updatePolicy(Request $request, string $ruleId): JsonResponse
    {
        $actor = $request->user();
        if ($actor === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $rule = CommissionRule::query()->findOrFail($ruleId);
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($actor, 'commissions.write');
        if (! $this->adminAccessService->isSuperAdmin($actor) && ($rule->scope_id === null || ! in_array((int) $rule->scope_id, $companyIds, true))) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'service_type' => ['nullable', 'string', 'max:64'],
            'percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_value' => ['nullable', 'numeric', 'min:0'],
            'fixed_currency' => ['nullable', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:active,inactive,scheduled'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('service_type', $validated)) {
            $rule->service_type = $validated['service_type'];
        }
        if (array_key_exists('percent', $validated) && $rule->type === 'percentage') {
            $rule->percentage_value = $validated['percent'];
        }
        if (array_key_exists('fixed_value', $validated) && $rule->type === 'fixed') {
            $rule->fixed_value = $validated['fixed_value'];
        }
        if (array_key_exists('fixed_currency', $validated)) {
            $rule->fixed_currency = $validated['fixed_currency'];
        }
        if (array_key_exists('effective_from', $validated)) {
            $rule->effective_from = $validated['effective_from'];
        }
        if (array_key_exists('effective_to', $validated)) {
            $rule->effective_to = $validated['effective_to'];
        }
        if (array_key_exists('status', $validated)) {
            $rule->status = $validated['status'];
            $rule->active = $validated['status'] === 'active';
        }
        if (array_key_exists('notes', $validated)) {
            $rule->notes = $validated['notes'];
        }
        $rule->save();

        return response()->json([
            'success' => true,
            'data' => $this->transformRuleToPolicyShape($rule->fresh()),
        ]);
    }

    /**
     * Phase 7.4 — soft-deactivate (status='inactive', active=false). The row
     * stays in the DB so historical commission_transactions keep their
     * snapshot reference, but the rule no longer matches new bookings.
     */
    public function deactivatePolicy(Request $request, string $ruleId): JsonResponse
    {
        $actor = $request->user();
        if ($actor === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $rule = CommissionRule::query()->findOrFail($ruleId);
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($actor, 'commissions.write');
        if (! $this->adminAccessService->isSuperAdmin($actor) && ($rule->scope_id === null || ! in_array((int) $rule->scope_id, $companyIds, true))) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $rule->status = 'inactive';
        $rule->active = false;
        $rule->save();

        return response()->json([
            'success' => true,
            'data' => $this->transformRuleToPolicyShape($rule->fresh()),
        ]);
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function canViewRule(Request $request, CommissionRule $rule, array $companyIds): bool
    {
        if ($rule->level === 'seller') {
            return $rule->scope_id !== null && in_array((int) $rule->scope_id, $companyIds, true);
        }

        if ($rule->level === 'global') {
            return $companyIds !== [] || $this->adminAccessService->isSuperAdmin($request->user());
        }

        return $this->adminAccessService->isSuperAdmin($request->user());
    }

    /**
     * @return array<string, mixed>
     */
    private function transformRuleToPolicyShape(CommissionRule $rule): array
    {
        return [
            'id' => $rule->id,
            'company_id' => $rule->level === 'seller' ? $rule->scope_id : null,
            'service_type' => $rule->service_type,
            'percent' => $rule->percentage_value !== null ? (float) $rule->percentage_value : null,
            'commission_mode' => $this->mapRuleTypeToLegacyCommissionMode($rule->type),
            'min_amount' => null,
            'max_amount' => null,
            'effective_from' => $rule->effective_from,
            'effective_to' => $rule->effective_to,
            'status' => $rule->status,
            'created_at' => $rule->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformTransactionToRecordShape(CommissionTransaction $transaction): array
    {
        $snapshot = is_array($transaction->snapshot) ? $transaction->snapshot : [];
        $serviceType = $snapshot['service_type'] ?? $transaction->rule?->service_type;
        $commissionMode = $this->mapRuleTypeToLegacyCommissionMode((string) ($snapshot['type'] ?? $transaction->rule?->type));
        $commissionValue = $snapshot['percentage_value'] ?? $snapshot['fixed_value'] ?? null;
        $subjectType = $transaction->order_item_id !== null ? 'order_item' : ($transaction->order_id !== null ? 'order' : null);
        $subjectId = $transaction->order_item_id ?? $transaction->order_id;

        return [
            'id' => $transaction->id,
            'commission_policy_id' => $transaction->rule_id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'company_id' => $transaction->seller_id,
            'service_type' => $serviceType,
            'commission_mode' => $commissionMode,
            'commission_value' => $commissionValue !== null ? (float) $commissionValue : null,
            'commission_amount_snapshot' => (float) $transaction->commission_amount,
            'currency' => $transaction->commission_currency,
            'status' => null,
            'created_at' => $transaction->created_at,
        ];
    }

    private function mapRuleTypeToLegacyCommissionMode(?string $ruleType): ?string
    {
        return match ($ruleType) {
            'percentage' => 'percent',
            'fixed' => 'fixed_amount',
            default => null,
        };
    }

    /**
     * @param  callable(CommissionRule|CommissionTransaction): array<string, mixed>  $transformer
     */
    private function paginatedLegacyResponse(LengthAwarePaginator $paginator, callable $transformer): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $paginator->getCollection()->map(fn ($item): array => $transformer($item))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }
}

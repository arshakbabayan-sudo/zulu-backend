<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Pricing\StorePricingRuleRequest;
use App\Http\Requests\Admin\Pricing\UpdatePricingRuleRequest;
use App\Http\Resources\Api\PricingRuleResource;
use App\Models\PricingRule;
use App\Services\Pricing\PricingAuditLogger;
use App\Services\Pricing\PricingResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 / Step D.1 — admin CRUD for pricing_rules.
 *
 * Routes (registered in routes/api.php):
 *   GET    /api/admin/pricing-rules            (list)
 *   POST   /api/admin/pricing-rules            (create)
 *   PATCH  /api/admin/pricing-rules/{rule}     (update)
 *   DELETE /api/admin/pricing-rules/{rule}     (soft delete + audit)
 *   POST   /api/admin/pricing-rules/test       (resolver dry-run)
 *
 * Super-admin only (enforced by Form Request authorize() + a controller
 * guard). For non-super read access (operator's own rules), see the
 * read-only `/api/operator/pricing-rules` route — left as a Phase-2
 * follow-up.
 */
class PricingRuleController extends Controller
{
    public function __construct(
        private PricingAuditLogger $auditor,
        private PricingResolver $resolver,
    ) {}

    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        if (! $request->user()?->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $query = PricingRule::query()->with(['operator:id,name', 'agent:id,name']);

        // Optional filters
        if ($scope = $request->query('scope_type')) {
            $query->where('scope_type', $scope);
        }
        if ($operator = $request->query('operator_id')) {
            $query->where('operator_id', (int) $operator);
        }
        if ($currency = $request->query('currency')) {
            $query->where('currency', strtoupper((string) $currency));
        }
        if ($category = $request->query('service_category')) {
            $query->where('service_category', $category);
        }
        if (($active = $request->query('is_active')) !== null && $active !== '') {
            $query->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));

        return PricingRuleResource::collection(
            $query->orderByDesc('priority')->orderByDesc('effective_from')->paginate($perPage)
        );
    }

    public function store(StorePricingRuleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $reason = $data['reason'] ?? null;
        unset($data['reason']);

        $rule = DB::transaction(function () use ($data, $request, $reason): PricingRule {
            $rule = PricingRule::create(array_merge(
                $data,
                ['created_by' => $request->user()->id],
            ));
            $this->auditor->logCreate($rule, $request->user(), $reason);

            return $rule;
        });

        return response()->json([
            'success' => true,
            'data' => new PricingRuleResource($rule->fresh(['operator:id,name', 'agent:id,name'])),
        ], 201);
    }

    public function update(UpdatePricingRuleRequest $request, PricingRule $pricingRule): JsonResponse
    {
        $data = $request->validated();
        $reason = $data['reason'] ?? null;
        unset($data['reason']);

        // Snapshot the pre-save state so the audit log captures the diff.
        $oldValues = collect($pricingRule->getAttributes())
            ->only($pricingRule->getFillable())
            ->all();

        $updated = DB::transaction(function () use ($pricingRule, $data, $oldValues, $request, $reason): PricingRule {
            $pricingRule->fill($data)->save();

            // Special-case (de)activation so the audit trail is human-readable.
            if (array_key_exists('is_active', $data)) {
                if ($oldValues['is_active'] === false && $data['is_active'] === true) {
                    $this->auditor->logReactivate($pricingRule, $request->user(), $reason);
                } elseif ($oldValues['is_active'] === true && $data['is_active'] === false) {
                    $this->auditor->logDeactivate($pricingRule, $request->user(), $reason);
                }
            }
            $this->auditor->logUpdate($pricingRule, $oldValues, $request->user(), $reason);

            return $pricingRule;
        });

        return response()->json([
            'success' => true,
            'data' => new PricingRuleResource($updated->fresh(['operator:id,name', 'agent:id,name'])),
        ]);
    }

    public function destroy(Request $request, PricingRule $pricingRule): JsonResponse
    {
        if (! $request->user()?->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $reason = (string) $request->input('reason', '');

        DB::transaction(function () use ($pricingRule, $request, $reason): void {
            $this->auditor->logDelete($pricingRule, $request->user(), $reason !== '' ? $reason : null);
            $pricingRule->delete(); // soft delete (SoftDeletes trait)
        });

        return response()->json(['success' => true, 'message' => 'Pricing rule deleted.']);
    }

    /**
     * POST /api/admin/pricing-rules/test
     *
     * Body: { offer_id: int, quantity?: int, agent_id?: int,
     *         destination_id?: int, price_override?: numeric }
     *
     * Returns the resolver's output for that hypothetical booking —
     * useful for previewing how a newly-created rule will affect prices
     * before flipping is_active=true.
     */
    public function test(Request $request): JsonResponse
    {
        if (! $request->user()?->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'agent_id' => ['nullable', 'integer', 'exists:companies,id'],
            'destination_id' => ['nullable', 'integer', 'exists:locations,id'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $this->resolver->resolve(
            offerId: (int) $validated['offer_id'],
            quantity: (int) ($validated['quantity'] ?? 1),
            buyerContext: array_filter([
                'agent_company_id' => $validated['agent_id'] ?? null,
                'destination_id' => $validated['destination_id'] ?? null,
                'price_override' => $validated['price_override'] ?? null,
                'buyer_type' => $request->input('buyer_type', 'client'),
            ], fn ($v) => $v !== null),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'offer_id' => $result->offerId,
                'quantity' => $result->quantity,
                'supplier_net' => $result->supplierNet,
                'customer_price' => $result->customerPrice,
                'line_total' => $result->lineTotal(),
                'currency' => $result->currency,
                'rule_id_applied' => $result->ruleIdApplied,
                'snapshot' => $result->snapshotPayload,
            ],
        ]);
    }
}

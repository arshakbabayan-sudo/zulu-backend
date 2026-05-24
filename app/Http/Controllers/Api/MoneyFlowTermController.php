<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Pricing\StoreMoneyFlowTermRequest;
use App\Http\Requests\Admin\Pricing\UpdateMoneyFlowTermRequest;
use App\Http\Resources\Api\MoneyFlowTermResource;
use App\Models\MoneyFlowTerm;
use App\Services\Pricing\PricingAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 / Step D.2 — admin CRUD for money_flow_terms. Mirrors
 * PricingRuleController. Super-admin only. Every mutation writes a
 * pricing_audit_log row via PricingAuditLogger.
 */
class MoneyFlowTermController extends Controller
{
    public function __construct(private PricingAuditLogger $auditor) {}

    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        if (! $request->user()?->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $query = MoneyFlowTerm::query()->with(['operator:id,name', 'agent:id,name']);

        if ($scope = $request->query('scope_type')) {
            $query->where('scope_type', $scope);
        }
        if ($operator = $request->query('operator_id')) {
            $query->where('operator_id', (int) $operator);
        }
        if ($model = $request->query('collection_model')) {
            $query->where('collection_model', $model);
        }
        if (($active = $request->query('is_active')) !== null && $active !== '') {
            $query->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));

        return MoneyFlowTermResource::collection(
            $query->orderByDesc('effective_from')->paginate($perPage)
        );
    }

    public function store(StoreMoneyFlowTermRequest $request): JsonResponse
    {
        $data = $request->validated();
        $reason = $data['reason'] ?? null;
        unset($data['reason']);
        if (! isset($data['effective_from'])) {
            $data['effective_from'] = now();
        }

        $term = DB::transaction(function () use ($data, $request, $reason): MoneyFlowTerm {
            $term = MoneyFlowTerm::create(array_merge(
                $data,
                ['created_by' => $request->user()->id],
            ));
            $this->auditor->logCreate($term, $request->user(), $reason);

            return $term;
        });

        return response()->json([
            'success' => true,
            'data' => new MoneyFlowTermResource($term->fresh(['operator:id,name', 'agent:id,name'])),
        ], 201);
    }

    public function update(UpdateMoneyFlowTermRequest $request, MoneyFlowTerm $moneyFlowTerm): JsonResponse
    {
        $data = $request->validated();
        $reason = $data['reason'] ?? null;
        unset($data['reason']);

        $oldValues = collect($moneyFlowTerm->getAttributes())
            ->only($moneyFlowTerm->getFillable())
            ->all();

        $updated = DB::transaction(function () use ($moneyFlowTerm, $data, $oldValues, $request, $reason): MoneyFlowTerm {
            $moneyFlowTerm->fill($data)->save();

            if (array_key_exists('is_active', $data)) {
                if ($oldValues['is_active'] === false && $data['is_active'] === true) {
                    $this->auditor->logReactivate($moneyFlowTerm, $request->user(), $reason);
                } elseif ($oldValues['is_active'] === true && $data['is_active'] === false) {
                    $this->auditor->logDeactivate($moneyFlowTerm, $request->user(), $reason);
                }
            }
            $this->auditor->logUpdate($moneyFlowTerm, $oldValues, $request->user(), $reason);

            return $moneyFlowTerm;
        });

        return response()->json([
            'success' => true,
            'data' => new MoneyFlowTermResource($updated->fresh(['operator:id,name', 'agent:id,name'])),
        ]);
    }

    public function destroy(Request $request, MoneyFlowTerm $moneyFlowTerm): JsonResponse
    {
        if (! $request->user()?->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $reason = (string) $request->input('reason', '');

        // Hard delete here — money_flow_terms doesn't use SoftDeletes.
        // The audit row preserves the trail even after the parent row
        // is gone.
        DB::transaction(function () use ($moneyFlowTerm, $request, $reason): void {
            $this->auditor->logDelete($moneyFlowTerm, $request->user(), $reason !== '' ? $reason : null);
            $moneyFlowTerm->delete();
        });

        return response()->json(['success' => true, 'message' => 'Money-flow term deleted.']);
    }
}

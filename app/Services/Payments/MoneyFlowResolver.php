<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\MoneyFlowTerm;
use App\Models\Order;
use App\Services\Payments\DTOs\MoneyFlowResolutionResult;
use Illuminate\Support\Carbon;

/**
 * Phase 1 / Step C.3 — money-flow resolver.
 *
 * Determines which collection model applies to an order:
 *   - Model A (zulu_collects)
 *   - Model B (operator_collects)
 *   - Model C (agent_collects)
 *
 * Customer bookings (no agent involved, booker_type='client') ALWAYS
 * use Model A with the platform's default remittance window — per
 * spec, money-flow choice only applies to agent-mediated bookings.
 *
 * Agent bookings walk priority:
 *   partnership > operator > global
 *
 * Default if no term resolves: Model A, T+15.
 */
class MoneyFlowResolver
{
    private const DEFAULT_REMITTANCE_DAYS = 15;

    /**
     * @param  Order|array{
     *   operator_id?: int|null,
     *   agent_company_id?: int|null,
     *   company_id?: int|null,
     *   buyer_type?: string,
     * } $orderLike  Either an Order instance OR an array describing one
     *               (used in tests + before-create flows).
     */
    public function resolve(Order|array $orderLike): MoneyFlowResolutionResult
    {
        $operatorId = $this->operatorIdOf($orderLike);
        $agentId = $this->agentIdOf($orderLike);
        $buyerType = $this->buyerTypeOf($orderLike);

        // Customer booking → always Model A.
        if ($agentId === null || $buyerType === 'client') {
            return $this->customerDefault($operatorId, $agentId);
        }

        $atTime = now();

        // partnership first
        if ($operatorId !== null) {
            $term = $this->findTerm(MoneyFlowTerm::SCOPE_PARTNERSHIP, $operatorId, $agentId, $atTime);
            if ($term !== null) {
                return $this->resultFromTerm($term, $operatorId, $agentId);
            }
        }

        // operator default
        if ($operatorId !== null) {
            $term = $this->findTerm(MoneyFlowTerm::SCOPE_OPERATOR, $operatorId, null, $atTime);
            if ($term !== null) {
                return $this->resultFromTerm($term, $operatorId, $agentId);
            }
        }

        // global
        $term = $this->findTerm(MoneyFlowTerm::SCOPE_GLOBAL, null, null, $atTime);
        if ($term !== null) {
            return $this->resultFromTerm($term, $operatorId, $agentId);
        }

        // No term at all → defensive default (Model A T+15).
        return new MoneyFlowResolutionResult(
            collectionModel: MoneyFlowTerm::MODEL_ZULU_COLLECTS,
            remittanceDays: self::DEFAULT_REMITTANCE_DAYS,
            invoicingPeriod: null,
            termIdApplied: null,
            scopeMatched: 'platform_default',
            snapshotPayload: [
                'engine' => 'phase_1_money_flow_default',
                'reason' => 'no money_flow_terms rows matched any level',
                'operator_id' => $operatorId,
                'agent_id' => $agentId,
                'buyer_type' => $buyerType,
                'computed_at' => now()->toIso8601String(),
            ],
        );
    }

    private function findTerm(string $scopeType, ?int $operatorId, ?int $agentId, Carbon $atTime): ?MoneyFlowTerm
    {
        $q = MoneyFlowTerm::query()
            ->active()
            ->effectiveAt($atTime)
            ->where('scope_type', $scopeType);

        if ($operatorId !== null) {
            $q->where('operator_id', $operatorId);
        } else {
            $q->whereNull('operator_id');
        }
        if ($agentId !== null) {
            $q->where('agent_id', $agentId);
        } else {
            $q->whereNull('agent_id');
        }

        return $q->orderByDesc('effective_from')->first();
    }

    private function customerDefault(?int $operatorId, ?int $agentId): MoneyFlowResolutionResult
    {
        // For customer bookings we STILL want the operator's remittance
        // window (if configured) — only the collection_model is forced
        // to zulu_collects. If no operator-default exists, fall back to
        // the platform default 15 days.
        $remittance = self::DEFAULT_REMITTANCE_DAYS;
        $termId = null;
        $scopeMatched = 'platform_default';

        if ($operatorId !== null) {
            $term = $this->findTerm(MoneyFlowTerm::SCOPE_OPERATOR, $operatorId, null, now());
            if ($term !== null && $term->collection_model === MoneyFlowTerm::MODEL_ZULU_COLLECTS) {
                $remittance = (int) ($term->remittance_days ?? self::DEFAULT_REMITTANCE_DAYS);
                $termId = (int) $term->id;
                $scopeMatched = 'operator_default_used_for_customer';
            }
        }

        return new MoneyFlowResolutionResult(
            collectionModel: MoneyFlowTerm::MODEL_ZULU_COLLECTS,
            remittanceDays: $remittance,
            invoicingPeriod: null,
            termIdApplied: $termId,
            scopeMatched: $scopeMatched,
            snapshotPayload: [
                'engine' => 'phase_1_money_flow_customer_default',
                'reason' => 'customer booking always uses Model A',
                'operator_id' => $operatorId,
                'agent_id' => $agentId,
                'computed_at' => now()->toIso8601String(),
            ],
        );
    }

    private function resultFromTerm(MoneyFlowTerm $term, ?int $operatorId, ?int $agentId): MoneyFlowResolutionResult
    {
        return new MoneyFlowResolutionResult(
            collectionModel: $term->collection_model,
            remittanceDays: $term->remittance_days === null ? null : (int) $term->remittance_days,
            invoicingPeriod: $term->invoicing_period,
            termIdApplied: (int) $term->id,
            scopeMatched: $term->scope_type,
            snapshotPayload: [
                'engine' => 'phase_1_money_flow_v1',
                'term_id' => (int) $term->id,
                'scope_type' => $term->scope_type,
                'collection_model' => $term->collection_model,
                'operator_id' => $operatorId,
                'agent_id' => $agentId,
                'computed_at' => now()->toIso8601String(),
            ],
        );
    }

    /** @param  Order|array<string, mixed>  $orderLike */
    private function operatorIdOf(Order|array $orderLike): ?int
    {
        if ($orderLike instanceof Order) {
            return $orderLike->company_id === null ? null : (int) $orderLike->company_id;
        }
        if (isset($orderLike['operator_id'])) {
            return (int) $orderLike['operator_id'];
        }
        if (isset($orderLike['company_id'])) {
            return (int) $orderLike['company_id'];
        }

        return null;
    }

    /** @param  Order|array<string, mixed>  $orderLike */
    private function agentIdOf(Order|array $orderLike): ?int
    {
        if ($orderLike instanceof Order) {
            return $orderLike->agent_company_id === null ? null : (int) $orderLike->agent_company_id;
        }

        return isset($orderLike['agent_company_id'])
            ? (int) $orderLike['agent_company_id']
            : null;
    }

    /** @param  Order|array<string, mixed>  $orderLike */
    private function buyerTypeOf(Order|array $orderLike): string
    {
        if ($orderLike instanceof Order) {
            return (string) ($orderLike->buyer_type ?? 'client');
        }

        return (string) ($orderLike['buyer_type'] ?? 'client');
    }
}

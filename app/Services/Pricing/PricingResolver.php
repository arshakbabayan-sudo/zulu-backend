<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Location;
use App\Models\Offer;
use App\Models\PricingRule;
use App\Services\Pricing\DTOs\PricingResolutionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Phase 1 / Step C.2 — real pricing resolver.
 *
 * Walks the 4-level priority order and returns the first match. Within
 * a level, ties are broken by:
 *   1. `priority` column desc (higher wins)
 *   2. Non-wildcard `service_category` beats wildcard
 *   3. Most-recent `effective_from` desc
 *
 * Math uses bcmath with scale=4, matching CommissionService::accrue()
 * (audit doc §1.3). Falls back to the Phase-1-stub 15% behaviour via
 * PriceCalculatorService when no rule matches at any level — gives the
 * Step C seeders some breathing room and prevents day-1 breakage.
 *
 * Currency handling:
 *  - Rules are currency-strict — a USD rule only matches a USD offer.
 *  - Cross-currency conversions for display happen elsewhere (FxConverter
 *    in C.4) using exchange_rates snapshot.
 */
class PricingResolver
{
    public function __construct(
        private PriceCalculatorService $calculator,
    ) {}

    /**
     * Resolve the customer-facing price for a single line item.
     *
     * @param  int  $offerId
     * @param  int  $quantity
     * @param  array{
     *   buyer_type?: string,
     *   agent_company_id?: int|null,
     *   customer_id?: int|null,
     *   price_override?: numeric|null,
     *   destination_id?: int|null,
     * } $buyerContext
     */
    public function resolve(int $offerId, int $quantity, array $buyerContext = []): PricingResolutionResult
    {
        if ($offerId <= 0) {
            throw new InvalidArgumentException('PricingResolver::resolve requires a positive offer_id.');
        }
        if ($quantity <= 0) {
            throw new InvalidArgumentException('PricingResolver::resolve requires quantity >= 1.');
        }

        /** @var Offer|null $offer */
        $offer = Offer::query()->find($offerId);
        if ($offer === null) {
            throw new InvalidArgumentException("Offer id={$offerId} not found.");
        }

        $supplierNet = isset($buyerContext['price_override']) && $buyerContext['price_override'] !== null
            ? (string) (float) $buyerContext['price_override']
            : (string) (float) ($offer->price ?? 0);

        $currency = strtoupper((string) ($offer->currency ?? 'USD'));
        $operatorId = (int) ($offer->company_id ?? 0);
        $agentId = isset($buyerContext['agent_company_id']) ? (int) $buyerContext['agent_company_id'] : null;
        $serviceCategory = $this->resolveServiceCategory($offer);
        $destinationId = $this->resolveDestinationId($buyerContext, $offer);
        $atTime = now();

        $rule = $this->findMatchingRule(
            currency: $currency,
            operatorId: $operatorId,
            agentId: $agentId,
            serviceCategory: $serviceCategory,
            destinationId: $destinationId,
            atTime: $atTime,
        );

        if ($rule === null) {
            // No rule matched at any level — fall back to the per-operator
            // markup percent (the operator's customer/agent tier, chosen by
            // buyer_type). When the operator has NO per-operator value the
            // method itself falls back to the global default (today's 15%),
            // so this stays backwards-compatible. This is the SAME method the
            // public catalog display calls → for a rule-less operator,
            // display(customer%) == client charge(customer%).
            $buyerType = isset($buyerContext['buyer_type']) && is_string($buyerContext['buyer_type'])
                ? $buyerContext['buyer_type']
                : 'client';

            $operatorCustomer = (string) $this->calculator->b2cPriceForOperator(
                (float) $supplierNet,
                $operatorId > 0 ? $operatorId : null,
                $buyerType,
            );

            return $this->buildResult(
                offerId: $offerId,
                quantity: $quantity,
                supplierNet: $supplierNet,
                customerPrice: $operatorCustomer,
                currency: $currency,
                rule: null,
                offer: $offer,
                buyerContext: $buyerContext,
                engine: 'operator_markup_percent',
            );
        }

        // Apply the resolved rule's markup with bcmath scale=4.
        $customerPrice = $this->applyMarkup($supplierNet, $rule);

        // Bound by min/max if set.
        if ($rule->min_sell_amount !== null && bccomp($customerPrice, (string) $rule->min_sell_amount, 4) === -1) {
            $customerPrice = bcadd((string) $rule->min_sell_amount, '0', 4);
        }
        if ($rule->max_sell_amount !== null && bccomp($customerPrice, (string) $rule->max_sell_amount, 4) === 1) {
            $customerPrice = bcadd((string) $rule->max_sell_amount, '0', 4);
        }

        return $this->buildResult(
            offerId: $offerId,
            quantity: $quantity,
            supplierNet: $supplierNet,
            customerPrice: $customerPrice,
            currency: $currency,
            rule: $rule,
            offer: $offer,
            buyerContext: $buyerContext,
            engine: 'phase_1_pricing_rules_v1',
        );
    }

    /**
     * Walk the 4-level priority order. Return the first level's best match.
     */
    private function findMatchingRule(
        string $currency,
        int $operatorId,
        ?int $agentId,
        ?string $serviceCategory,
        ?int $destinationId,
        Carbon $atTime,
    ): ?PricingRule {
        $hierarchyDestinationIds = $this->destinationHierarchyIds($destinationId);

        // Build candidate-fetch helper.
        $levelLookup = function (string $scopeType, ?int $operator, ?int $agent) use (
            $currency, $serviceCategory, $hierarchyDestinationIds, $atTime
        ): ?PricingRule {
            $q = PricingRule::query()
                ->active()
                ->effectiveAt($atTime)
                ->forLevel($scopeType)
                ->where('currency', $currency);

            if ($operator !== null) {
                $q->where('operator_id', $operator);
            }
            if ($agent !== null) {
                $q->where('agent_id', $agent);
            }

            // service_category filter: rule may be wildcard (NULL) or
            // exact match.
            if ($serviceCategory !== null) {
                $q->where(function ($q2) use ($serviceCategory): void {
                    $q2->whereNull('service_category')
                        ->orWhere('service_category', $serviceCategory);
                });
            } else {
                $q->whereNull('service_category');
            }

            // destination filter (hierarchical): match exact id OR any
            // ancestor id (rule on France matches Paris offers via the
            // location path).
            if ($hierarchyDestinationIds !== []) {
                $q->where(function ($q2) use ($hierarchyDestinationIds): void {
                    $q2->whereNull('destination_id')
                        ->orWhereIn('destination_id', $hierarchyDestinationIds);
                });
            } else {
                $q->whereNull('destination_id');
            }

            $candidates = $q->get();
            if ($candidates->isEmpty()) {
                return null;
            }

            return $this->pickBest($candidates);
        };

        // Priority order
        if ($agentId !== null && $operatorId > 0) {
            $hit = $levelLookup(PricingRule::SCOPE_PARTNERSHIP, $operatorId, $agentId);
            if ($hit !== null) {
                return $hit;
            }
        }

        if ($operatorId > 0) {
            $hit = $levelLookup(PricingRule::SCOPE_OPERATOR, $operatorId, null);
            if ($hit !== null) {
                return $hit;
            }
        }

        if ($serviceCategory !== null) {
            $hit = $levelLookup(PricingRule::SCOPE_CATEGORY, null, null);
            if ($hit !== null) {
                return $hit;
            }
        }

        return $levelLookup(PricingRule::SCOPE_GLOBAL, null, null);
    }

    /**
     * @param  Collection<int, PricingRule>  $candidates
     */
    private function pickBest(Collection $candidates): PricingRule
    {
        return $candidates
            ->sort(function (PricingRule $a, PricingRule $b): int {
                // 1. Higher priority wins
                if ($a->priority !== $b->priority) {
                    return $b->priority <=> $a->priority;
                }
                // 2. Non-wildcard service_category wins
                $aWild = $a->service_category === null ? 1 : 0;
                $bWild = $b->service_category === null ? 1 : 0;
                if ($aWild !== $bWild) {
                    return $aWild <=> $bWild;
                }
                // 3. More specific destination (deeper in hierarchy) wins
                $aDest = $a->destination_id === null ? 0 : 1;
                $bDest = $b->destination_id === null ? 0 : 1;
                if ($aDest !== $bDest) {
                    return $bDest <=> $aDest;
                }
                // 4. Most-recent effective_from wins
                return $b->effective_from->getTimestamp() <=> $a->effective_from->getTimestamp();
            })
            ->values()
            ->first();
    }

    private function applyMarkup(string $supplierNet, PricingRule $rule): string
    {
        if ($rule->markup_type === PricingRule::TYPE_FIXED) {
            // Fixed amount ADDED to supplier net.
            return bcadd($supplierNet, (string) $rule->markup_value, 4);
        }
        // percentage
        return bcadd(
            $supplierNet,
            bcdiv(bcmul($supplierNet, (string) $rule->markup_value, 8), '100', 4),
            4
        );
    }

    private function resolveServiceCategory(Offer $offer): ?string
    {
        $type = $offer->type ?? null;
        if (! is_string($type) || $type === '') {
            return null;
        }
        $allowed = ['hotel', 'flight', 'transfer', 'car', 'excursion', 'package', 'visa'];

        return in_array($type, $allowed, true) ? $type : null;
    }

    /**
     * @param  array<string, mixed>  $buyerContext
     */
    private function resolveDestinationId(array $buyerContext, Offer $offer): ?int
    {
        if (isset($buyerContext['destination_id']) && $buyerContext['destination_id'] !== null) {
            return (int) $buyerContext['destination_id'];
        }
        // Some offer types carry the destination via the related module
        // (Hotel.location_id, Excursion.location_id, etc.) — Phase 1
        // resolver doesn't traverse those eagerly to keep the query
        // count flat. Step D resolver can opt in to richer location
        // resolution.
        return null;
    }

    /**
     * For hierarchical destination matching, returns the destination's
     * own id PLUS every ancestor id (via Location.path). A rule on
     * France matches Paris offers without an explicit Paris row.
     *
     * @return list<int>
     */
    private function destinationHierarchyIds(?int $destinationId): array
    {
        if ($destinationId === null) {
            return [];
        }

        /** @var Location|null $loc */
        $loc = Location::query()->find($destinationId);
        if ($loc === null) {
            return [$destinationId];
        }

        $ids = [$loc->id];
        $path = (string) ($loc->path ?? '');
        if ($path !== '') {
            foreach (explode('/', $path) as $segment) {
                $segment = trim($segment);
                if ($segment !== '' && is_numeric($segment)) {
                    $ids[] = (int) $segment;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $buyerContext
     */
    private function buildResult(
        int $offerId,
        int $quantity,
        string $supplierNet,
        string $customerPrice,
        string $currency,
        ?PricingRule $rule,
        Offer $offer,
        array $buyerContext,
        string $engine,
    ): PricingResolutionResult {
        $supplierNetFloat = (float) $supplierNet;
        $customerFloat = (float) $customerPrice;

        return new PricingResolutionResult(
            offerId: $offerId,
            quantity: $quantity,
            supplierNet: $supplierNetFloat,
            customerPrice: $customerFloat,
            currency: $currency,
            ruleIdApplied: $rule?->id,
            snapshotPayload: [
                'engine' => $engine,
                'engine_version' => 2,
                'supplier_net' => $supplierNetFloat,
                'customer_price' => $customerFloat,
                'currency' => $currency,
                'offer_company_id' => $offer->company_id ?? null,
                'buyer_context' => $buyerContext,
                'rule' => $rule === null ? null : [
                    'id' => $rule->id,
                    'scope_type' => $rule->scope_type,
                    'operator_id' => $rule->operator_id,
                    'agent_id' => $rule->agent_id,
                    'service_category' => $rule->service_category,
                    'destination_id' => $rule->destination_id,
                    'markup_type' => $rule->markup_type,
                    'markup_value' => (string) $rule->markup_value,
                    'min_sell_amount' => $rule->min_sell_amount === null ? null : (string) $rule->min_sell_amount,
                    'max_sell_amount' => $rule->max_sell_amount === null ? null : (string) $rule->max_sell_amount,
                    'currency' => $rule->currency,
                    'priority' => $rule->priority,
                ],
                'computed_at' => now()->toIso8601String(),
            ],
        );
    }
}

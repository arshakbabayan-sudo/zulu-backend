<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPricingSnapshot;
use App\Services\Payments\DTOs\MoneyFlowResolutionResult;
use App\Services\Payments\MoneyFlowResolver;
use App\Services\Pricing\DTOs\PricingResolutionResult;
use App\Services\Pricing\PricingResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderService
{
    public function __construct(
        private PricingResolver $pricingResolver,
        private MoneyFlowResolver $moneyFlowResolver,
    ) {}

    /**
     * Create an Order with one or more OrderItem rows in a single transaction.
     *
     * Phase 1 / Step B.4 — SIGNATURE CHANGED.
     * Callers MUST pass `offer_id` in every item. The service resolves the
     * price internally via PricingResolver. `unit_price` from callers is
     * REJECTED — that was the markup-bypass attack surface (audit doc §4).
     *
     * $orderData: subset of Order fillable fields. `currency` is required.
     * Optional: order_number, user_id, company_id, agent_company_id,
     * buyer_type (default 'client'), status (default 'cart'), notes,
     * metadata, payment_id.
     * subtotal / tax / total are computed from resolved item prices and
     * ignored if supplied.
     *
     * $itemsData: array of payloads. Required per item:
     *   - item_type  (string, must be in OrderItem::ITEM_TYPES)
     *   - currency   (string)
     *   - offer_id   (int — PricingResolver resolves the price from this)
     * Optional: item_id, package_id, quantity (default 1),
     * service_snapshot, passenger_data, date_from, date_to,
     * status (default 'pending'), external_ref, parent_index,
     * price_override (operator-side; e.g. package component override).
     *
     * `parent_index` is the only nesting mechanism: integer position within
     * $itemsData pointing at the parent item (must precede the child in
     * the array), or null/absent for top-level. The service resolves
     * parent_index -> parent UUID in a 2-pass write. Direct
     * `parent_item_id` keys in $itemsData are IGNORED.
     *
     * @param  array<string, mixed>  $orderData
     * @param  array<int, array<string, mixed>>  $itemsData
     */
    public function create(array $orderData, array $itemsData): Order
    {
        if ($itemsData === []) {
            throw new InvalidArgumentException('OrderService::create requires at least one item.');
        }

        if (! isset($orderData['currency']) || ! is_string($orderData['currency']) || $orderData['currency'] === '') {
            throw new InvalidArgumentException('orderData.currency is required.');
        }

        // Phase 1 / B.4 — reject caller-supplied unit_price. This used to
        // be the markup-bypass attack surface (audit doc §4): a caller
        // could send any value and OrderService would store it verbatim.
        foreach ($itemsData as $idx => $item) {
            if (array_key_exists('unit_price', $item)) {
                throw new InvalidArgumentException(
                    "itemsData[{$idx}].unit_price is no longer accepted (Phase 1 / B.4). "
                    .'Pass offer_id + optional price_override; service resolves price via PricingResolver.'
                );
            }

            if (! isset($item['parent_index']) || $item['parent_index'] === null) {
                continue;
            }

            $parentIndex = $item['parent_index'];
            if (! is_int($parentIndex) || $parentIndex < 0 || $parentIndex >= $idx) {
                throw new InvalidArgumentException("itemsData[{$idx}].parent_index must be an int < {$idx}.");
            }
        }

        // Pre-resolve prices for every item so the transaction below uses
        // already-computed values (faster, and keeps the resolver outside
        // the DB transaction lock).
        $resolvedByIndex = [];
        $agentCompanyId = $orderData['agent_company_id'] ?? null;
        $buyerType = $orderData['buyer_type'] ?? 'client';

        foreach ($itemsData as $idx => $item) {
            $offerId = isset($item['offer_id']) ? (int) $item['offer_id'] : 0;
            if ($offerId <= 0) {
                throw new InvalidArgumentException(
                    "itemsData[{$idx}].offer_id is required (Phase 1 / B.4 contract)."
                );
            }
            $quantity = (int) ($item['quantity'] ?? 1);
            $buyerContext = [
                'buyer_type' => $buyerType,
                'agent_company_id' => $agentCompanyId,
                'customer_id' => $orderData['user_id'] ?? null,
                'price_override' => $item['price_override'] ?? null,
            ];

            $resolvedByIndex[$idx] = $this->pricingResolver->resolve($offerId, $quantity, $buyerContext);
        }

        // Resolve money flow once for the whole order (it depends on the
        // order-level operator/agent/buyer triple, not on individual items).
        $moneyFlow = $this->moneyFlowResolver->resolve([
            'operator_id' => $orderData['company_id'] ?? null,
            'agent_company_id' => $orderData['agent_company_id'] ?? null,
            'buyer_type' => $orderData['buyer_type'] ?? 'client',
        ]);

        return DB::transaction(function () use ($orderData, $itemsData, $resolvedByIndex, $moneyFlow): Order {
            $subtotal = 0.0;
            foreach ($resolvedByIndex as $resolved) {
                $subtotal += $resolved->lineTotal();
            }

            $order = Order::query()->create([
                'order_number' => $orderData['order_number'] ?? null,
                'user_id' => $orderData['user_id'] ?? null,
                'company_id' => $orderData['company_id'] ?? null,
                'agent_company_id' => $orderData['agent_company_id'] ?? null,
                'buyer_type' => $orderData['buyer_type'] ?? 'client',
                'status' => $orderData['status'] ?? 'cart',
                'currency' => strtoupper($orderData['currency']),
                'subtotal' => $subtotal,
                'tax' => 0,
                'total' => $subtotal,
                'payment_id' => $orderData['payment_id'] ?? null,
                'notes' => $orderData['notes'] ?? null,
                'metadata' => $orderData['metadata'] ?? null,
            ]);

            $idByIndex = [];

            foreach ($itemsData as $idx => $item) {
                if (isset($item['parent_index']) && $item['parent_index'] !== null) {
                    continue;
                }

                $createdItem = $this->createItem($order, $item, $resolvedByIndex[$idx], null);
                $idByIndex[$idx] = $createdItem->id;
                $this->writeSnapshot($order, $createdItem, $resolvedByIndex[$idx], $moneyFlow);
            }

            foreach ($itemsData as $idx => $item) {
                if (! isset($item['parent_index']) || $item['parent_index'] === null) {
                    continue;
                }

                $parentUuid = $idByIndex[$item['parent_index']]
                    ?? throw new InvalidArgumentException(
                        "itemsData[{$idx}].parent_index points to a non-top-level item - only one level of nesting is supported."
                    );

                $createdItem = $this->createItem($order, $item, $resolvedByIndex[$idx], $parentUuid);
                $idByIndex[$idx] = $createdItem->id;
                $this->writeSnapshot($order, $createdItem, $resolvedByIndex[$idx], $moneyFlow);
            }

            return $order->fresh(['items']);
        });
    }

    /**
     * Phase 1 / Step F — persist the immutable pricing snapshot for an
     * order item into the dedicated `order_pricing_snapshots` table.
     *
     * The full resolver payloads (pricing + money-flow) are merged into
     * snapshot_json so finance audits can reconstruct exactly how the
     * line price was computed, including which rule won at which scope
     * level and the FX rate (if any) used.
     */
    private function writeSnapshot(
        Order $order,
        OrderItem $item,
        PricingResolutionResult $pricing,
        MoneyFlowResolutionResult $moneyFlow,
    ): void {
        $snapshotJson = $pricing->snapshotPayload;
        $snapshotJson['money_flow'] = $moneyFlow->snapshotPayload;

        // Phase Զ.16 / Item 19 — agent-net split.
        // When an order has an agent_company_id, the markup (customer - supplier)
        // is split 50/50 by default between the operator and the agent:
        //   agent_net    = supplier_net + (markup * 0.5)   // what agent owes operator
        //   agent_margin = customer_paid - agent_net       // agent's profit slice
        // No agent → both fields stay null.
        $agentNet = null;
        $agentMargin = null;
        if ($order->agent_company_id !== null) {
            $markup = $pricing->customerPrice - $pricing->supplierNet;
            if ($markup > 0) {
                // Default 50/50 split. Future enhancement: read agent_share_pct
                // off a pricing-rule or partnership row.
                $agentShare = 0.5;
                $agentNet = round($pricing->supplierNet + ($markup * $agentShare), 2);
                $agentMargin = round($pricing->customerPrice - $agentNet, 2);
            } else {
                // No markup → no margin for the agent either.
                $agentNet = $pricing->supplierNet;
                $agentMargin = 0.0;
            }
        }

        OrderPricingSnapshot::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'offer_id' => $pricing->offerId,
            'supplier_net' => $pricing->supplierNet,
            // Markup VALUE recorded matches what the rule applied: for
            // percentage rules it's the % (e.g. 15), for fixed it's the
            // added amount. Resolver knows; snapshot stores both.
            'zulu_markup_value' => (string) (
                ($snapshotJson['rule']['markup_value'] ?? null)
                ?? ($pricing->customerPrice - $pricing->supplierNet)
            ),
            'zulu_markup_type' => $snapshotJson['rule']['markup_type']
                ?? ($pricing->ruleIdApplied === null ? 'legacy' : 'percentage'),
            'agent_net' => $agentNet,
            'customer_paid' => $pricing->customerPrice,
            'agent_margin' => $agentMargin,
            'rule_id_applied' => $pricing->ruleIdApplied,
            'currency' => $pricing->currency,
            'snapshot_json' => $snapshotJson,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createItem(
        Order $order,
        array $item,
        \App\Services\Pricing\DTOs\PricingResolutionResult $resolved,
        ?string $parentItemId
    ): OrderItem {
        if (! isset($item['item_type']) || ! in_array($item['item_type'], OrderItem::ITEM_TYPES, true)) {
            throw new InvalidArgumentException('Invalid or missing item_type: '.var_export($item['item_type'] ?? null, true));
        }

        if (! isset($item['currency']) || ! is_string($item['currency']) || $item['currency'] === '') {
            throw new InvalidArgumentException('item.currency is required.');
        }

        $quantity = $resolved->quantity;
        $unitPrice = $resolved->customerPrice;
        $total = $resolved->lineTotal();

        // Persist the resolver's snapshot inside service_snapshot.pricing
        // so finance / audit can reconstruct exactly how the price was
        // computed for this line. Real Phase 1 / Step C moves this to a
        // dedicated `order_pricing_snapshots` table.
        $callerSnapshot = is_array($item['service_snapshot'] ?? null) ? $item['service_snapshot'] : [];
        $callerSnapshot['pricing'] = $resolved->snapshotPayload;
        $callerSnapshot['offer_id'] = $resolved->offerId;

        return $order->items()->create([
            'item_type' => $item['item_type'],
            'item_id' => $item['item_id'] ?? null,
            'package_id' => $item['package_id'] ?? null,
            'parent_item_id' => $parentItemId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $total,
            'currency' => strtoupper($item['currency']),
            'service_snapshot' => $callerSnapshot,
            'passenger_data' => $item['passenger_data'] ?? null,
            'date_from' => $item['date_from'] ?? null,
            'date_to' => $item['date_to'] ?? null,
            'status' => $item['status'] ?? 'pending',
            'external_ref' => $item['external_ref'] ?? null,
        ]);
    }
}

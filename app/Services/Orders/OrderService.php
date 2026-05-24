<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Pricing\PricingResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderService
{
    public function __construct(
        private PricingResolver $pricingResolver,
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

        return DB::transaction(function () use ($orderData, $itemsData, $resolvedByIndex): Order {
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

                $idByIndex[$idx] = $this->createItem($order, $item, $resolvedByIndex[$idx], null)->id;
            }

            foreach ($itemsData as $idx => $item) {
                if (! isset($item['parent_index']) || $item['parent_index'] === null) {
                    continue;
                }

                $parentUuid = $idByIndex[$item['parent_index']]
                    ?? throw new InvalidArgumentException(
                        "itemsData[{$idx}].parent_index points to a non-top-level item - only one level of nesting is supported."
                    );

                $idByIndex[$idx] = $this->createItem($order, $item, $resolvedByIndex[$idx], $parentUuid)->id;
            }

            return $order->fresh(['items']);
        });
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

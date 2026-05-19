<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderService
{
    /**
     * Create an Order with one or more OrderItem rows in a single transaction.
     *
     * $orderData: subset of Order fillable fields. `currency` is required.
     * Optional: order_number, user_id, company_id, buyer_type (default 'client'),
     * status (default 'cart'), notes, metadata, payment_id.
     * subtotal / tax / total are computed from items and ignored if supplied.
     *
     * $itemsData: array of payloads. Required per item: item_type (must be in
     * OrderItem::ITEM_TYPES), currency, unit_price. Optional: item_id, package_id,
     * quantity (default 1), service_snapshot, passenger_data, date_from, date_to,
     * status (default 'pending'), external_ref, parent_index.
     *
     * `parent_index` is the only nesting mechanism: integer position within $itemsData
     * pointing at the parent item (must precede the child in the array), or null/absent
     * for top-level. The service resolves parent_index -> parent UUID in a 2-pass write.
     * Direct `parent_item_id` keys in $itemsData are IGNORED (avoids UUID-before-write
     * paradox).
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

        foreach ($itemsData as $idx => $item) {
            if (! isset($item['parent_index']) || $item['parent_index'] === null) {
                continue;
            }

            $parentIndex = $item['parent_index'];
            if (! is_int($parentIndex) || $parentIndex < 0 || $parentIndex >= $idx) {
                throw new InvalidArgumentException("itemsData[{$idx}].parent_index must be an int < {$idx}.");
            }
        }

        return DB::transaction(function () use ($orderData, $itemsData): Order {
            $subtotal = 0.0;
            foreach ($itemsData as $item) {
                $quantity = (int) ($item['quantity'] ?? 1);
                $unitPrice = (float) ($item['unit_price'] ?? 0);
                $subtotal += $quantity * $unitPrice;
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

                $idByIndex[$idx] = $this->createItem($order, $item, null)->id;
            }

            foreach ($itemsData as $idx => $item) {
                if (! isset($item['parent_index']) || $item['parent_index'] === null) {
                    continue;
                }

                $parentUuid = $idByIndex[$item['parent_index']]
                    ?? throw new InvalidArgumentException(
                        "itemsData[{$idx}].parent_index points to a non-top-level item - only one level of nesting is supported."
                    );

                $idByIndex[$idx] = $this->createItem($order, $item, $parentUuid)->id;
            }

            return $order->fresh(['items']);
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createItem(Order $order, array $item, ?string $parentItemId): OrderItem
    {
        if (! isset($item['item_type']) || ! in_array($item['item_type'], OrderItem::ITEM_TYPES, true)) {
            throw new InvalidArgumentException('Invalid or missing item_type: '.var_export($item['item_type'] ?? null, true));
        }

        if (! isset($item['currency']) || ! is_string($item['currency']) || $item['currency'] === '') {
            throw new InvalidArgumentException('item.currency is required.');
        }

        $quantity = (int) ($item['quantity'] ?? 1);
        $unitPrice = (float) ($item['unit_price'] ?? 0);
        $total = $item['total'] ?? ($quantity * $unitPrice);

        return $order->items()->create([
            'item_type' => $item['item_type'],
            'item_id' => $item['item_id'] ?? null,
            'package_id' => $item['package_id'] ?? null,
            'parent_item_id' => $parentItemId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $total,
            'currency' => strtoupper($item['currency']),
            'service_snapshot' => $item['service_snapshot'] ?? null,
            'passenger_data' => $item['passenger_data'] ?? null,
            'date_from' => $item['date_from'] ?? null,
            'date_to' => $item['date_to'] ?? null,
            'status' => $item['status'] ?? 'pending',
            'external_ref' => $item['external_ref'] ?? null,
        ]);
    }
}

<?php

namespace App\Services\Cart;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * PART 22 — Cart management. A cart is an Order with status='cart'.
 * One open cart per user (created lazily on first item add).
 */
class CartService
{
    /**
     * Get the user's existing open cart, or create a new one.
     */
    public function getOrCreateCart(User $user, string $currency = 'USD'): Order
    {
        $existing = Order::query()
            ->where('user_id', $user->id)
            ->where('status', 'cart')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Order::query()->create([
            'order_number' => $this->generateCartNumber($user),
            'user_id' => $user->id,
            'buyer_type' => 'client',
            'status' => 'cart',
            'currency' => strtoupper($currency),
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
        ]);
    }

    /**
     * Add an item to the user's cart. Returns the (possibly newly created) cart.
     *
     * @param  array<string, mixed>  $item
     *      Required: item_type, currency, unit_price
     *      Optional: item_id, package_id, quantity, service_snapshot, passenger_data,
     *                date_from, date_to, external_ref
     */
    public function addItem(User $user, array $item): Order
    {
        if (! isset($item['item_type']) || ! in_array($item['item_type'], OrderItem::ITEM_TYPES, true)) {
            throw new InvalidArgumentException('Invalid or missing item_type.');
        }
        if (! isset($item['currency']) || ! is_string($item['currency']) || $item['currency'] === '') {
            throw new InvalidArgumentException('item.currency is required.');
        }

        return DB::transaction(function () use ($user, $item): Order {
            $cart = $this->getOrCreateCart($user, $item['currency']);

            // Lock cart row to avoid concurrent modification
            Order::query()->where('id', $cart->id)->lockForUpdate()->first();

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $total = $item['total'] ?? ($quantity * $unitPrice);

            $cart->items()->create([
                'item_type' => $item['item_type'],
                'item_id' => $item['item_id'] ?? null,
                'package_id' => $item['package_id'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $total,
                'currency' => strtoupper($item['currency']),
                'service_snapshot' => $item['service_snapshot'] ?? null,
                'passenger_data' => $item['passenger_data'] ?? null,
                'date_from' => $item['date_from'] ?? null,
                'date_to' => $item['date_to'] ?? null,
                'status' => 'pending',
                'external_ref' => $item['external_ref'] ?? null,
            ]);

            $this->recomputeTotals($cart);

            return $cart->fresh(['items']);
        });
    }

    /**
     * Update an existing item's quantity. quantity=0 removes it.
     */
    public function updateItemQuantity(User $user, string $itemId, int $quantity): Order
    {
        return DB::transaction(function () use ($user, $itemId, $quantity): Order {
            $cart = $this->requireOpenCart($user);
            $item = $cart->items()->whereKey($itemId)->first();
            if ($item === null) {
                throw new RuntimeException('Item not found in cart.');
            }

            if ($quantity <= 0) {
                $item->delete();
            } else {
                $item->quantity = $quantity;
                $item->total = (float) $item->unit_price * $quantity;
                $item->save();
            }

            $this->recomputeTotals($cart);

            return $cart->fresh(['items']);
        });
    }

    public function removeItem(User $user, string $itemId): Order
    {
        return $this->updateItemQuantity($user, $itemId, 0);
    }

    public function clearCart(User $user): Order
    {
        return DB::transaction(function () use ($user): Order {
            $cart = $this->requireOpenCart($user);
            $cart->items()->delete();
            $this->recomputeTotals($cart);

            return $cart->fresh(['items']);
        });
    }

    public function findOpenCart(User $user): ?Order
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->where('status', 'cart')
            ->with('items')
            ->first();
    }

    private function requireOpenCart(User $user): Order
    {
        $cart = $this->findOpenCart($user);
        if ($cart === null) {
            throw new RuntimeException('No open cart for user.');
        }

        return $cart;
    }

    private function recomputeTotals(Order $cart): void
    {
        $items = $cart->items()->get();
        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += (float) $item->total;
        }

        $cart->subtotal = $subtotal;
        $cart->tax = 0;
        $cart->total = $subtotal;
        $cart->save();
    }

    private function generateCartNumber(User $user): string
    {
        return 'CART-'.$user->id.'-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }
}

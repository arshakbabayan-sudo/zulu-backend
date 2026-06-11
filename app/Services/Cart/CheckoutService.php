<?php

namespace App\Services\Cart;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Availability\BlockedDateChecker;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PART 22 — Checkout. Converts a cart Order to pending_payment, freezes a
 * service_snapshot for each item (the immutable record of what was bought
 * at this moment in time), and assigns a real order_number.
 *
 * Inventory hold (15-min default) is recorded in metadata.held_until so a
 * subsequent payment job knows how long to honor the cart's prices.
 */
class CheckoutService
{
    public const DEFAULT_HOLD_MINUTES = 15;

    public function __construct(
        private CartService $cartService,
        private ?BlockedDateChecker $blockedDateChecker = null,
    ) {}

    private function blockedDates(): BlockedDateChecker
    {
        return $this->blockedDateChecker ?? app(BlockedDateChecker::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     *                                         Optional: notes, passenger_data (applied to items lacking own data),
     *                                         booking_channel, hold_minutes (override default)
     */
    public function checkout(User $user, array $payload = []): Order
    {
        $cart = $this->cartService->findOpenCart($user);
        if ($cart === null) {
            throw new RuntimeException('No open cart to checkout.');
        }

        $items = $cart->items()->get();
        if ($items->isEmpty()) {
            throw new RuntimeException('Cart is empty — cannot checkout.');
        }

        $holdMinutes = max(1, (int) ($payload['hold_minutes'] ?? self::DEFAULT_HOLD_MINUTES));

        return DB::transaction(function () use ($cart, $items, $payload, $holdMinutes): Order {
            // Lock the cart row
            Order::query()->where('id', $cart->id)->lockForUpdate()->first();

            // Reload after lock
            $cart->refresh();
            if ($cart->status !== 'cart') {
                throw new RuntimeException("Cart is not in 'cart' status (current: {$cart->status}).");
            }

            // Freeze snapshots and apply optional shared passenger_data fallback
            $sharedPassengerData = is_array($payload['passenger_data'] ?? null) ? $payload['passenger_data'] : null;

            foreach ($items as $item) {
                // Roadmap §4 — refuse checkout when an item's travel window
                // falls on operator-blocked dates (covers blocks added after
                // the item was carted). Items without dates are skipped.
                $this->blockedDates()->assertOrderItemNotBlocked(
                    (string) $item->item_type,
                    $item->item_id,
                    $item->date_from?->toDateString(),
                    $item->date_to?->toDateString(),
                );

                if ($item->service_snapshot === null) {
                    $item->service_snapshot = $this->buildSnapshot($item);
                }

                if ($item->passenger_data === null && $sharedPassengerData !== null) {
                    $item->passenger_data = $sharedPassengerData;
                }

                $item->save();
            }

            $cart->status = 'pending_payment';
            $cart->order_number = $this->generateOrderNumber();
            if (isset($payload['notes']) && is_string($payload['notes'])) {
                $cart->notes = $payload['notes'];
            }

            $metadata = is_array($cart->metadata) ? $cart->metadata : [];
            $metadata['checkout_at'] = now()->toIso8601String();
            $metadata['held_until'] = now()->addMinutes($holdMinutes)->toIso8601String();
            $metadata['hold_minutes'] = $holdMinutes;
            if (isset($payload['booking_channel'])) {
                $metadata['booking_channel'] = (string) $payload['booking_channel'];
            }
            $cart->metadata = $metadata;

            $cart->save();

            return $cart->fresh(['items']);
        });
    }

    /**
     * Returns the count of carts that have expired their hold window
     * and should be released back to status='cart'.
     */
    public function releaseExpiredHolds(): int
    {
        $expired = Order::query()
            ->where('status', 'pending_payment')
            ->whereNotNull('metadata')
            ->get()
            ->filter(function (Order $order): bool {
                $heldUntil = $order->metadata['held_until'] ?? null;

                return is_string($heldUntil) && now()->greaterThan($heldUntil);
            });

        $count = 0;
        foreach ($expired as $order) {
            $order->status = 'cart';
            $metadata = $order->metadata;
            $metadata['released_at'] = now()->toIso8601String();
            unset($metadata['held_until']);
            $order->metadata = $metadata;
            $order->save();
            $count++;
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshot(OrderItem $item): array
    {
        return [
            'item_type' => $item->item_type,
            'item_id' => $item->item_id,
            'package_id' => $item->package_id,
            'unit_price' => (float) $item->unit_price,
            'quantity' => $item->quantity,
            'total' => (float) $item->total,
            'currency' => $item->currency,
            'date_from' => $item->date_from?->toDateString(),
            'date_to' => $item->date_to?->toDateString(),
            'snapshot_at' => now()->toIso8601String(),
        ];
    }

    private function generateOrderNumber(): string
    {
        return 'ORD-'.now()->format('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }
}

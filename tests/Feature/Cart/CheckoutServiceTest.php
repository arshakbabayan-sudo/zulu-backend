<?php

namespace Tests\Feature\Cart;

use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Cart\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CheckoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private CartService $cart;

    private CheckoutService $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cart = app(CartService::class);
        $this->checkout = app(CheckoutService::class);
    }

    public function test_checkout_converts_cart_to_pending_payment_with_snapshot(): void
    {
        $user = User::factory()->create();
        $this->cart->addItem($user, ['item_type' => 'hotel', 'currency' => 'USD', 'unit_price' => 100, 'quantity' => 2]);

        $order = $this->checkout->checkout($user);

        $this->assertSame('pending_payment', $order->status);
        $this->assertStringStartsWith('ORD-', $order->order_number);
        $this->assertSame(200.0, (float) $order->total);

        $item = $order->items->first();
        $this->assertNotNull($item->service_snapshot);
        $this->assertSame('hotel', $item->service_snapshot['item_type']);
        $this->assertSame(100.0, (float) $item->service_snapshot['unit_price']);
        $this->assertSame(200.0, (float) $item->service_snapshot['total']);

        // Hold metadata
        $this->assertArrayHasKey('held_until', $order->metadata);
        $this->assertArrayHasKey('checkout_at', $order->metadata);
        $this->assertSame(15, $order->metadata['hold_minutes']);
    }

    public function test_checkout_with_shared_passenger_data_applies_to_items(): void
    {
        $user = User::factory()->create();
        $this->cart->addItem($user, ['item_type' => 'flight', 'currency' => 'USD', 'unit_price' => 300]);

        $order = $this->checkout->checkout($user, [
            'passenger_data' => ['adults' => [['name' => 'John Doe', 'passport' => 'AM123']]],
            'notes' => 'Vegan meal please',
            'booking_channel' => 'public_b2c',
            'hold_minutes' => 30,
        ]);

        $this->assertSame('Vegan meal please', $order->notes);
        $this->assertSame(30, $order->metadata['hold_minutes']);
        $this->assertSame('public_b2c', $order->metadata['booking_channel']);

        $item = $order->items->first();
        $this->assertSame('John Doe', $item->passenger_data['adults'][0]['name']);
    }

    public function test_checkout_throws_when_no_cart(): void
    {
        $user = User::factory()->create();
        $this->expectException(RuntimeException::class);
        $this->checkout->checkout($user);
    }

    public function test_checkout_throws_when_cart_empty(): void
    {
        $user = User::factory()->create();
        $this->cart->getOrCreateCart($user); // empty cart
        $this->expectException(RuntimeException::class);
        $this->checkout->checkout($user);
    }

    public function test_existing_snapshot_is_preserved(): void
    {
        $user = User::factory()->create();
        $this->cart->addItem($user, [
            'item_type' => 'hotel',
            'currency' => 'USD',
            'unit_price' => 100,
            'service_snapshot' => ['custom' => 'preset', 'item_type' => 'hotel'],
        ]);

        $order = $this->checkout->checkout($user);
        $item = $order->items->first();

        $this->assertSame('preset', $item->service_snapshot['custom']);
    }

    public function test_release_expired_holds_resets_status_to_cart(): void
    {
        $user = User::factory()->create();
        $this->cart->addItem($user, ['item_type' => 'hotel', 'currency' => 'USD', 'unit_price' => 100]);
        $order = $this->checkout->checkout($user, ['hold_minutes' => 15]);

        // Manually expire the hold
        $metadata = $order->metadata;
        $metadata['held_until'] = now()->subMinute()->toIso8601String();
        $order->metadata = $metadata;
        $order->save();

        $released = $this->checkout->releaseExpiredHolds();

        $this->assertSame(1, $released);
        $this->assertSame('cart', $order->fresh()->status);
        $this->assertArrayHasKey('released_at', $order->fresh()->metadata);
    }

    public function test_release_expired_holds_skips_active_holds(): void
    {
        $user = User::factory()->create();
        $this->cart->addItem($user, ['item_type' => 'hotel', 'currency' => 'USD', 'unit_price' => 100]);
        $this->checkout->checkout($user, ['hold_minutes' => 60]);

        $released = $this->checkout->releaseExpiredHolds();

        $this->assertSame(0, $released);
    }
}

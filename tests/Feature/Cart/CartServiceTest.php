<?php

namespace Tests\Feature\Cart;

use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class CartServiceTest extends TestCase
{
    use RefreshDatabase;

    private CartService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CartService::class);
    }

    public function test_get_or_create_cart_creates_one_for_new_user(): void
    {
        $user = User::factory()->create();

        $cart = $this->service->getOrCreateCart($user);

        $this->assertSame('cart', $cart->status);
        $this->assertSame($user->id, $cart->user_id);
        $this->assertSame(0.0, (float) $cart->total);
        $this->assertStringStartsWith('CART-'.$user->id.'-', $cart->order_number);
    }

    public function test_get_or_create_cart_returns_existing_open_cart(): void
    {
        $user = User::factory()->create();
        $first = $this->service->getOrCreateCart($user);
        $second = $this->service->getOrCreateCart($user);

        $this->assertSame($first->id, $second->id);
    }

    public function test_add_item_creates_cart_lazily_and_recomputes_total(): void
    {
        $user = User::factory()->create();

        $cart = $this->service->addItem($user, [
            'item_type' => 'hotel',
            'currency' => 'USD',
            'unit_price' => 100,
            'quantity' => 2,
        ]);

        $this->assertSame('cart', $cart->status);
        $this->assertCount(1, $cart->items);
        $this->assertSame(200.0, (float) $cart->total);
    }

    public function test_add_item_appends_to_existing_cart(): void
    {
        $user = User::factory()->create();
        $this->service->addItem($user, ['item_type' => 'hotel', 'currency' => 'USD', 'unit_price' => 100]);
        $cart = $this->service->addItem($user, ['item_type' => 'flight', 'currency' => 'USD', 'unit_price' => 250]);

        $this->assertCount(2, $cart->items);
        $this->assertSame(350.0, (float) $cart->total);
    }

    public function test_add_item_rejects_invalid_type(): void
    {
        $user = User::factory()->create();
        $this->expectException(InvalidArgumentException::class);
        $this->service->addItem($user, ['item_type' => 'bogus', 'currency' => 'USD', 'unit_price' => 50]);
    }

    public function test_update_item_quantity_recomputes_totals(): void
    {
        $user = User::factory()->create();
        $cart = $this->service->addItem($user, [
            'item_type' => 'hotel',
            'currency' => 'USD',
            'unit_price' => 100,
            'quantity' => 1,
        ]);

        $itemId = $cart->items->first()->id;
        $updated = $this->service->updateItemQuantity($user, $itemId, 3);

        $this->assertSame(3, (int) $updated->items->first()->quantity);
        $this->assertSame(300.0, (float) $updated->items->first()->total);
        $this->assertSame(300.0, (float) $updated->total);
    }

    public function test_update_item_quantity_zero_removes_item(): void
    {
        $user = User::factory()->create();
        $cart = $this->service->addItem($user, ['item_type' => 'hotel', 'currency' => 'USD', 'unit_price' => 100]);
        $itemId = $cart->items->first()->id;

        $updated = $this->service->updateItemQuantity($user, $itemId, 0);

        $this->assertCount(0, $updated->items);
        $this->assertSame(0.0, (float) $updated->total);
    }

    public function test_remove_item_works(): void
    {
        $user = User::factory()->create();
        $cart = $this->service->addItem($user, ['item_type' => 'hotel', 'currency' => 'USD', 'unit_price' => 100]);
        $itemId = $cart->items->first()->id;

        $updated = $this->service->removeItem($user, $itemId);
        $this->assertCount(0, $updated->items);
    }

    public function test_clear_cart_removes_all_items(): void
    {
        $user = User::factory()->create();
        $this->service->addItem($user, ['item_type' => 'hotel', 'currency' => 'USD', 'unit_price' => 100]);
        $this->service->addItem($user, ['item_type' => 'flight', 'currency' => 'USD', 'unit_price' => 250]);

        $cleared = $this->service->clearCart($user);
        $this->assertCount(0, $cleared->items);
        $this->assertSame(0.0, (float) $cleared->total);
    }

    public function test_clear_cart_throws_when_no_cart(): void
    {
        $user = User::factory()->create();
        $this->expectException(RuntimeException::class);
        $this->service->clearCart($user);
    }

    public function test_find_open_cart_returns_null_when_none(): void
    {
        $user = User::factory()->create();
        $this->assertNull($this->service->findOpenCart($user));
    }

    public function test_add_item_rejects_a_different_currency_in_the_same_cart(): void
    {
        $user = User::factory()->create();
        $this->service->addItem($user, ['item_type' => 'hotel', 'currency' => 'USD', 'unit_price' => 100]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->addItem($user, ['item_type' => 'transfer', 'currency' => 'AMD', 'unit_price' => 40000]);
    }

    public function test_add_item_keeps_same_currency_items(): void
    {
        $user = User::factory()->create();
        $this->service->addItem($user, ['item_type' => 'hotel', 'currency' => 'usd', 'unit_price' => 100]);
        $cart = $this->service->addItem($user, ['item_type' => 'flight', 'currency' => 'USD', 'unit_price' => 250]);

        // Case-insensitive match; total is a single-currency sum.
        $this->assertCount(2, $cart->items);
        $this->assertSame(350.0, (float) $cart->total);
        $this->assertSame('USD', strtoupper((string) $cart->currency));
    }

    public function test_emptied_cart_adopts_a_new_currency(): void
    {
        $user = User::factory()->create();
        $this->service->addItem($user, ['item_type' => 'hotel', 'currency' => 'USD', 'unit_price' => 100]);
        $this->service->clearCart($user);

        // After clearing, the (still-open) cart may take a different currency.
        $cart = $this->service->addItem($user, ['item_type' => 'transfer', 'currency' => 'AMD', 'unit_price' => 40000]);

        $this->assertCount(1, $cart->items);
        $this->assertSame('AMD', strtoupper((string) $cart->currency));
        $this->assertSame(40000.0, (float) $cart->total);
    }
}

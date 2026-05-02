<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerCartControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_requires_auth(): void
    {
        $this->getJson('/api/customer/cart')->assertStatus(401);
    }

    public function test_show_returns_null_when_no_cart(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/customer/cart');
        $response->assertOk();
        $this->assertNull($response->json('data'));
    }

    public function test_add_item_creates_cart_and_returns_201(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/customer/cart/items', [
            'item_type' => 'hotel',
            'unit_price' => 100,
            'currency' => 'USD',
            'quantity' => 2,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'cart');
        $response->assertJsonPath('data.total', '200.00');
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_add_item_validates_type(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/customer/cart/items', [
            'item_type' => 'bogus',
            'unit_price' => 100,
            'currency' => 'USD',
        ])->assertStatus(422);
    }

    public function test_update_item_quantity(): void
    {
        $user = User::factory()->create();
        $cart = app(CartService::class)->addItem($user, [
            'item_type' => 'hotel', 'currency' => 'USD', 'unit_price' => 100, 'quantity' => 1,
        ]);
        $itemId = $cart->items->first()->id;

        Sanctum::actingAs($user);
        $response = $this->patchJson('/api/customer/cart/items/'.$itemId, ['quantity' => 5]);

        $response->assertOk();
        $this->assertSame('500.00', $response->json('data.total'));
    }

    public function test_remove_item(): void
    {
        $user = User::factory()->create();
        $cart = app(CartService::class)->addItem($user, [
            'item_type' => 'flight', 'currency' => 'USD', 'unit_price' => 200,
        ]);
        $itemId = $cart->items->first()->id;

        Sanctum::actingAs($user);
        $response = $this->deleteJson('/api/customer/cart/items/'.$itemId);
        $response->assertOk();
        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_clear_cart(): void
    {
        $user = User::factory()->create();
        app(CartService::class)->addItem($user, ['item_type' => 'hotel', 'currency' => 'USD', 'unit_price' => 50]);

        Sanctum::actingAs($user);
        $this->deleteJson('/api/customer/cart')->assertOk();
        $this->assertCount(0, $this->getJson('/api/customer/cart')->json('data.items'));
    }

    public function test_clear_cart_returns_404_when_no_cart(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->deleteJson('/api/customer/cart')->assertStatus(404);
    }

    public function test_checkout_converts_to_pending_payment(): void
    {
        $user = User::factory()->create();
        app(CartService::class)->addItem($user, [
            'item_type' => 'hotel', 'currency' => 'USD', 'unit_price' => 100,
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/customer/cart/checkout', [
            'notes' => 'test',
            'hold_minutes' => 30,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'pending_payment');
        $response->assertJsonPath('data.notes', 'test');
        $this->assertStringStartsWith('ORD-', $response->json('data.order_number'));
    }

    public function test_checkout_returns_422_when_no_cart(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/customer/cart/checkout', [])->assertStatus(422);
    }
}

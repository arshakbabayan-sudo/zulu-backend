<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Cart\CheckoutService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCartControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_abandoned_requires_platform_admin(): void
    {
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);
        $this->getJson('/api/platform-admin/abandoned-carts')->assertStatus(403);
    }

    public function test_abandoned_lists_carts_older_than_cutoff(): void
    {
        $admin = $this->createPlatformAdmin();
        $this->makeAbandonedCart(48); // 2 days old
        $this->makeAbandonedCart(2);  // 2 hours old (recent)

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/abandoned-carts?hours=24');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(24, $response->json('meta.hours'));
    }

    public function test_release_expired_holds_returns_count(): void
    {
        $admin = $this->createPlatformAdmin();

        // Create one expired pending_payment order
        $user = User::factory()->create();
        $cart = app(CartService::class)->addItem($user, [
            'item_type' => 'hotel', 'currency' => 'USD', 'unit_price' => 100,
        ]);
        $order = app(CheckoutService::class)->checkout($user, ['hold_minutes' => 1]);
        $metadata = $order->metadata;
        $metadata['held_until'] = now()->subMinute()->toIso8601String();
        $order->metadata = $metadata;
        $order->save();

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/cart/release-expired-holds');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.released'));
        $this->assertSame('cart', $order->fresh()->status);
    }

    public function test_stats_returns_status_counts(): void
    {
        $admin = $this->createPlatformAdmin();
        $this->makeAbandonedCart(48);
        $this->makeAbandonedCart(2);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/cart/stats');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.open_carts'));
        $this->assertSame(1, $response->json('data.abandoned_24h'));
    }

    private function makeAbandonedCart(int $hoursAgo): Order
    {
        $user = User::factory()->create();
        $cart = app(CartService::class)->addItem($user, [
            'item_type' => 'hotel', 'currency' => 'USD', 'unit_price' => 100,
        ]);

        // Backdate updated_at by writing directly
        DB::table('orders')
            ->where('id', $cart->id)
            ->update(['updated_at' => now()->subHours($hoursAgo)]);

        return $cart->fresh();
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}

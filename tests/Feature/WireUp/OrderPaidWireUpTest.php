<?php

namespace Tests\Feature\WireUp;

use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Services\Cart\CartService;
use App\Services\Packages\PackageOrderService;
use App\Services\Webhooks\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderPaidWireUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_paid_credits_loyalty_account(): void
    {
        $user = User::factory()->create();
        $order = $this->buildPaidOrder($user, 200);

        $account = LoyaltyAccount::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($account);
        $this->assertSame(200, $account->points_balance); // 200 USD × 1pt × bronze 1.0
    }

    public function test_mark_paid_dispatches_order_paid_webhook_to_subscribers(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $user = User::factory()->create();
        $company = \App\Models\Company::query()->create([
            'name' => 'WH Co', 'type' => 'operator', 'status' => 'active',
        ]);

        // Subscribe to order.paid
        $sub = app(WebhookService::class)->subscribe($company, [
            'target_url' => 'https://example.com/order-paid',
            'events' => ['order.paid'],
        ]);

        $order = $this->buildPaidOrder($user, 100);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://example.com/order-paid');
        $this->assertSame(1, $sub->fresh()->deliveries()->count());
    }

    private function buildPaidOrder(User $user, float $total): Order
    {
        // Build a cart-shaped order then mark paid
        $order = Order::query()->create([
            'order_number' => 'ORD-WIRE-'.str()->random(6),
            'user_id' => $user->id,
            'buyer_type' => 'client',
            'status' => 'pending_payment',
            'currency' => 'USD',
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'hotel',
            'quantity' => 1,
            'unit_price' => $total,
            'total' => $total,
            'currency' => 'USD',
            'status' => 'pending',
        ]);

        app(PackageOrderService::class)->markPaid($order);

        return $order->fresh(['items']);
    }
}

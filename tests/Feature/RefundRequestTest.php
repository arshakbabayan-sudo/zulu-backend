<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Customer-initiated refund requests (account section #12). */
class RefundRequestTest extends TestCase
{
    use RefreshDatabase;

    private function order(int $userId, string $status, string $number): Order
    {
        return Order::query()->create([
            'order_number' => $number,
            'user_id' => $userId,
            'status' => $status,
            'currency' => 'USD',
            'total' => 100,
        ]);
    }

    public function test_refund_request_flow_and_guards(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $paid = $this->order($user->id, 'paid', 'R-1');
        $cart = $this->order($user->id, 'cart', 'R-2');
        $foreign = $this->order($other->id, 'paid', 'R-3');

        // Happy path on an owned, paid order.
        $this->postJson('/api/account/refund-requests', ['order_id' => $paid->id, 'reason' => 'Trip cancelled'])
            ->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->getJson('/api/account/refund-requests')->assertOk()->assertJsonCount(1, 'data');

        // A second pending request for the same order is blocked.
        $this->postJson('/api/account/refund-requests', ['order_id' => $paid->id, 'reason' => 'again'])->assertStatus(422);

        // Not-yet-paid order is ineligible.
        $this->postJson('/api/account/refund-requests', ['order_id' => $cart->id, 'reason' => 'x'])->assertStatus(422);

        // Someone else's order cannot be refund-requested.
        $this->postJson('/api/account/refund-requests', ['order_id' => $foreign->id, 'reason' => 'x'])->assertStatus(422);
    }
}

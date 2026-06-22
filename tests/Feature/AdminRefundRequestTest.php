<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Payments\PaymentGatewayService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * §8 — admin refund-request queue. The customer's refund request (created via
 * the account Support flow) is reviewed here; approval issues the real gateway
 * refund and notifies the customer.
 */
class AdminRefundRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_list_queue(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/platform-admin/refund-requests')->assertStatus(403);
    }

    public function test_admin_lists_queue_with_stats(): void
    {
        [$rr] = $this->makeRefundRequest();
        Sanctum::actingAs($this->platformAdmin());

        $this->getJson('/api/platform-admin/refund-requests?status=pending')
            ->assertOk()
            ->assertJsonPath('meta.stats.pending', 1)
            ->assertJsonCount(1, 'data');
    }

    public function test_approve_issues_gateway_refund_flips_statuses_and_notifies(): void
    {
        [$rr, $payment, $order, $customer] = $this->makeRefundRequest();

        $this->mock(PaymentGatewayService::class, function (MockInterface $mock) use ($payment): void {
            $mock->shouldReceive('refundPaymentIntent')
                ->once()
                ->withArgs(fn (Payment $p, ?int $cents): bool => $p->is($payment) && $cents === null)
                ->andReturn(['success' => true]);
        });

        Sanctum::actingAs($admin = $this->platformAdmin());

        $this->postJson("/api/platform-admin/refund-requests/{$rr->id}/approve", ['admin_notes' => 'ok'])
            ->assertOk()
            ->assertJsonPath('data.status', RefundRequest::STATUS_APPROVED);

        $this->assertDatabaseHas('refund_requests', [
            'id' => $rr->id,
            'status' => RefundRequest::STATUS_APPROVED,
            'reviewed_by' => $admin->id,
            'admin_notes' => 'ok',
        ]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => Payment::STATUS_REFUNDED]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $customer->id,
            'event_type' => 'refund.approved',
        ]);
    }

    public function test_approve_partial_amount_keeps_payment_paid(): void
    {
        [$rr, $payment] = $this->makeRefundRequest(amountRequested: 40.00); // payment is 100

        $this->mock(PaymentGatewayService::class, function (MockInterface $mock) use ($payment): void {
            $mock->shouldReceive('refundPaymentIntent')
                ->once()
                ->withArgs(fn (Payment $p, ?int $cents): bool => $p->is($payment) && $cents === 4000)
                ->andReturn(['success' => true]);
        });

        Sanctum::actingAs($this->platformAdmin());

        $this->postJson("/api/platform-admin/refund-requests/{$rr->id}/approve")->assertOk();

        // Partial refund leaves the payment paid (no partially_refunded state).
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => Payment::STATUS_PAID]);
    }

    public function test_reject_sets_status_and_notifies_without_refund(): void
    {
        [$rr, , , $customer] = $this->makeRefundRequest();

        $this->mock(PaymentGatewayService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('refundPaymentIntent');
        });

        Sanctum::actingAs($this->platformAdmin());

        $this->postJson("/api/platform-admin/refund-requests/{$rr->id}/reject", ['admin_notes' => 'not eligible'])
            ->assertOk()
            ->assertJsonPath('data.status', RefundRequest::STATUS_REJECTED);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $customer->id,
            'event_type' => 'refund.rejected',
        ]);
    }

    public function test_cannot_approve_already_reviewed_request(): void
    {
        [$rr] = $this->makeRefundRequest(status: RefundRequest::STATUS_APPROVED);
        Sanctum::actingAs($this->platformAdmin());

        $this->postJson("/api/platform-admin/refund-requests/{$rr->id}/approve")->assertStatus(422);
    }

    /**
     * Audit C4 (§5) — claim-before-money-out ordering: the request must already
     * be flipped to APPROVED at the moment the gateway is hit, so a concurrent
     * transaction reading the row would see non-pending and bail. We assert the
     * status inside the gateway-refund callback.
     */
    public function test_request_is_claimed_inside_lock_before_gateway_call(): void
    {
        [$rr, $payment] = $this->makeRefundRequest();

        $this->mock(PaymentGatewayService::class, function (MockInterface $mock) use ($rr): void {
            $mock->shouldReceive('refundPaymentIntent')
                ->once()
                ->andReturnUsing(function () use ($rr): array {
                    // The claim must already be committed-in-transaction before
                    // the money leaves: the row is APPROVED, not PENDING.
                    $this->assertSame(
                        RefundRequest::STATUS_APPROVED,
                        RefundRequest::query()->whereKey($rr->id)->value('status'),
                    );

                    return ['success' => true];
                });
        });

        Sanctum::actingAs($this->platformAdmin());
        $this->postJson("/api/platform-admin/refund-requests/{$rr->id}/approve")->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => Payment::STATUS_REFUNDED]);
    }

    /**
     * Audit C4 (§5) — two approvals of the SAME request issue EXACTLY ONE
     * gateway refund (one money-out). The second is rejected with no gateway
     * call. On SQLite lockForUpdate is a no-op, so this exercises the
     * in-transaction status re-check that is the real cross-engine guard.
     */
    public function test_double_approval_triggers_only_one_gateway_refund(): void
    {
        [$rr, $payment] = $this->makeRefundRequest();

        // The gateway may be called at most once across BOTH approval attempts.
        $this->mock(PaymentGatewayService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refundPaymentIntent')
                ->once()
                ->andReturn(['success' => true]);
        });

        Sanctum::actingAs($this->platformAdmin());

        $first = $this->postJson("/api/platform-admin/refund-requests/{$rr->id}/approve");
        $first->assertOk()->assertJsonPath('data.status', RefundRequest::STATUS_APPROVED);

        // Second attempt on the now-approved request: no second money-out.
        $second = $this->postJson("/api/platform-admin/refund-requests/{$rr->id}/approve");
        $this->assertContains($second->status(), [409, 422]);
        $second->assertJsonPath('success', false);

        // Exactly one refund booked: cumulative = full amount, paid once.
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => Payment::STATUS_REFUNDED,
        ]);
        $this->assertSame('100.00', (string) $payment->fresh()->refunded_amount);
    }

    /**
     * Audit C4 (§5) — a request already claimed by a concurrent writer (status
     * no longer pending) is rejected by the controller's authoritative re-check
     * with NO gateway money-out. This is the cross-engine guard that protects PG
     * and SQLite alike (lockForUpdate is a no-op on SQLite).
     */
    public function test_already_claimed_request_is_rejected_without_money_out(): void
    {
        [$rr] = $this->makeRefundRequest();

        // No gateway call is allowed: an already-claimed request must bail
        // before any money-out.
        $this->mock(PaymentGatewayService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('refundPaymentIntent');
        });

        // Simulate the race winner having already committed its claim.
        RefundRequest::query()->whereKey($rr->id)->update(['status' => RefundRequest::STATUS_APPROVED]);

        Sanctum::actingAs($this->platformAdmin());
        $resp = $this->postJson("/api/platform-admin/refund-requests/{$rr->id}/approve");

        $this->assertContains($resp->status(), [409, 422]);
        $resp->assertJsonPath('success', false);
    }

    /**
     * @return array{0: RefundRequest, 1: Payment, 2: Order, 3: User}
     */
    private function makeRefundRequest(
        ?float $amountRequested = null,
        string $status = RefundRequest::STATUS_PENDING,
    ): array {
        $customer = User::factory()->create();
        $order = Order::query()->create([
            'order_number' => 'RF-'.str()->uuid(),
            'user_id' => $customer->id,
            'status' => 'paid',
            'currency' => 'USD',
            'total' => 100,
        ]);
        $invoice = Invoice::query()->create([
            'order_id' => $order->id,
            'total_amount' => 100.00,
            'currency' => 'usd',
            'status' => Invoice::STATUS_PAID ?? 'paid',
        ]);
        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => 100.00,
            'currency' => 'usd',
            'status' => Payment::STATUS_PAID,
            'payment_method' => 'card',
            'reference_code' => 'pi_'.str()->uuid(),
        ]);
        $rr = RefundRequest::query()->create([
            'user_id' => $customer->id,
            'order_id' => $order->id,
            'reason' => 'changed plans',
            'amount_requested' => $amountRequested,
            'status' => $status,
        ]);

        return [$rr, $payment, $order, $customer];
    }

    private function platformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}

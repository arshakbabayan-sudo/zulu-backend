<?php

namespace Tests\Feature;

use App\Exceptions\PaymentRefundFailedException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayService;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class PaymentRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_calls_gateway_and_updates_status_on_success(): void
    {
        $payment = $this->createPaymentWithStatus(Payment::STATUS_PAID);

        $this->mock(PaymentGatewayService::class, function (MockInterface $mock) use ($payment): void {
            $mock->shouldReceive('refundPaymentIntent')
                ->once()
                ->withArgs(fn (Payment $arg): bool => $arg->is($payment))
                ->andReturn([
                    'success' => true,
                    'refund_id' => 're_test_123',
                    'status' => 'succeeded',
                ]);
        });

        $service = app(PaymentService::class);
        $refreshed = $service->refund($payment);

        $this->assertSame(Payment::STATUS_REFUNDED, $refreshed->status);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => Payment::STATUS_REFUNDED,
        ]);
    }

    public function test_refund_throws_when_gateway_fails_and_does_not_mutate_db(): void
    {
        $payment = $this->createPaymentWithStatus(Payment::STATUS_PAID);

        $this->mock(PaymentGatewayService::class, function (MockInterface $mock) use ($payment): void {
            $mock->shouldReceive('refundPaymentIntent')
                ->once()
                ->withArgs(fn (Payment $arg): bool => $arg->is($payment))
                ->andReturn([
                    'success' => false,
                    'error' => 'Stripe error X',
                ]);
        });

        $service = app(PaymentService::class);

        $this->expectException(PaymentRefundFailedException::class);
        $this->expectExceptionMessage('Payment refund failed: Stripe error X');

        try {
            $service->refund($payment);
        } finally {
            $this->assertDatabaseHas('payments', [
                'id' => $payment->id,
                'status' => Payment::STATUS_PAID,
            ]);
        }
    }

    public function test_refund_is_idempotent_on_already_refunded_payment(): void
    {
        $payment = $this->createPaymentWithStatus(Payment::STATUS_REFUNDED);

        $this->mock(PaymentGatewayService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('refundPaymentIntent');
        });

        $service = app(PaymentService::class);
        $refreshed = $service->refund($payment);

        $this->assertSame(Payment::STATUS_REFUNDED, $refreshed->status);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => Payment::STATUS_REFUNDED,
        ]);
    }

    public function test_refund_rejects_non_paid_payment(): void
    {
        $payment = $this->createPaymentWithStatus(Payment::STATUS_PENDING);

        $this->mock(PaymentGatewayService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('refundPaymentIntent');
        });

        $service = app(PaymentService::class);

        $this->expectException(PaymentRefundFailedException::class);
        $this->expectExceptionMessage(
            'Payment refund failed: Only paid payments can be refunded (current: pending)'
        );

        $service->refund($payment);
    }

    /**
     * Audit C4 (§5) — a first single FULL refund must behave exactly as before:
     * gateway called once with null, status → REFUNDED, and the new cumulative
     * column is set to the full amount.
     */
    public function test_first_full_refund_succeeds_and_sets_cumulative_to_amount(): void
    {
        $payment = $this->createPaymentWithStatus(Payment::STATUS_PAID);

        $this->mock(PaymentGatewayService::class, function (MockInterface $mock) use ($payment): void {
            $mock->shouldReceive('refundPaymentIntent')
                ->once()
                ->withArgs(fn (Payment $arg, ?int $cents): bool => $arg->is($payment) && $cents === null)
                ->andReturn(['success' => true]);
        });

        $refreshed = app(PaymentService::class)->refund($payment);

        $this->assertSame(Payment::STATUS_REFUNDED, $refreshed->status);
        $this->assertSame('100.00', (string) $refreshed->refunded_amount);
    }

    /**
     * Audit C4 (§5) — repeated partial refunds accumulate, and the one that
     * would push the cumulative past the paid amount is REJECTED before any
     * gateway money-out.
     */
    public function test_partial_refunds_are_capped_at_paid_amount(): void
    {
        $payment = $this->createPaymentWithStatus(Payment::STATUS_PAID); // 100.00

        // First two partials (60 + 30 = 90) succeed; the gateway is called for
        // each. The third (20) would total 110 > 100 → rejected, no 3rd call.
        $this->mock(PaymentGatewayService::class, function (MockInterface $mock) use ($payment): void {
            $mock->shouldReceive('refundPaymentIntent')
                ->once()
                ->withArgs(fn (Payment $p, ?int $cents): bool => $p->is($payment) && $cents === 6000)
                ->andReturn(['success' => true]);
            $mock->shouldReceive('refundPaymentIntent')
                ->once()
                ->withArgs(fn (Payment $p, ?int $cents): bool => $p->is($payment) && $cents === 3000)
                ->andReturn(['success' => true]);
        });

        $service = app(PaymentService::class);

        $service->refund($payment->fresh(), 6000);
        $this->assertSame('60.00', (string) $payment->fresh()->refunded_amount);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);

        $service->refund($payment->fresh(), 3000);
        $this->assertSame('90.00', (string) $payment->fresh()->refunded_amount);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);

        // Over-cap: 90 + 20 = 110 > 100 → rejected, gateway NOT called a 3rd time.
        try {
            $service->refund($payment->fresh(), 2000);
            $this->fail('Expected an over-cap refund to be rejected.');
        } catch (PaymentRefundFailedException $e) {
            $this->assertStringContainsString('exceeds the remaining refundable balance', $e->reason);
        }

        // Cumulative unchanged; still paid (not fully refunded).
        $this->assertSame('90.00', (string) $payment->fresh()->refunded_amount);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    /**
     * Audit C4 (§5) — partials that exactly sum to the paid amount settle the
     * payment to REFUNDED.
     */
    public function test_partial_refunds_summing_to_full_flip_to_refunded(): void
    {
        $payment = $this->createPaymentWithStatus(Payment::STATUS_PAID); // 100.00

        $this->mock(PaymentGatewayService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refundPaymentIntent')->twice()->andReturn(['success' => true]);
        });

        $service = app(PaymentService::class);
        $service->refund($payment->fresh(), 4000);
        $refreshed = $service->refund($payment->fresh(), 6000);

        $this->assertSame('100.00', (string) $refreshed->refunded_amount);
        $this->assertSame(Payment::STATUS_REFUNDED, $refreshed->status);
    }

    private function createPaymentWithStatus(string $status): Payment
    {
        $invoice = Invoice::query()->create([
            'total_amount' => 100.00,
            'currency' => 'usd',
            'status' => Invoice::STATUS_PENDING,
        ]);

        return Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => 100.00,
            'currency' => 'usd',
            'status' => $status,
            'reference_code' => 'pi_test_'.$status.'_'.str()->uuid(),
        ]);
    }
}

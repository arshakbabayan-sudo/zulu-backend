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

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentGatewayService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Roadmap 10.06 §5 — POST /payments/{payment}/retry.
 *
 * A failed gateway payment gets a fresh payment intent and flips back to
 * pending; non-failed payments and non-gateway methods are 422.
 */
class PaymentRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_creates_new_intent_and_resets_to_pending(): void
    {
        $payment = $this->createPayment(Payment::STATUS_FAILED, 'card');

        $this->mock(PaymentGatewayService::class, function (MockInterface $mock) use ($payment): void {
            $mock->shouldReceive('createPaymentIntent')
                ->once()
                ->withArgs(fn (Payment $arg): bool => $arg->is($payment))
                ->andReturn([
                    'success' => true,
                    'client_secret' => 'cs_test_retry',
                    'gateway_reference' => 'pi_test_retry',
                ]);
        });

        Sanctum::actingAs($this->createPlatformAdmin());
        $response = $this->postJson("/api/payments/{$payment->id}/retry");

        $response->assertOk();
        $this->assertSame('cs_test_retry', $response->json('client_secret'));
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => Payment::STATUS_PENDING,
        ]);
    }

    public function test_retry_rejects_non_failed_payment(): void
    {
        $payment = $this->createPayment(Payment::STATUS_PAID, 'card');

        $this->mock(PaymentGatewayService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createPaymentIntent');
        });

        Sanctum::actingAs($this->createPlatformAdmin());
        $this->postJson("/api/payments/{$payment->id}/retry")->assertStatus(422);
    }

    public function test_retry_rejects_non_gateway_method(): void
    {
        $payment = $this->createPayment(Payment::STATUS_FAILED, 'bank_transfer');

        $this->mock(PaymentGatewayService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createPaymentIntent');
        });

        Sanctum::actingAs($this->createPlatformAdmin());
        $this->postJson("/api/payments/{$payment->id}/retry")->assertStatus(422);
    }

    private function createPayment(string $status, string $method): Payment
    {
        $company = Company::query()->create(['name' => 'Retry Co '.uniqid(), 'type' => 'operator']);
        $order = Order::query()->create([
            'order_number' => 'RT-'.str()->uuid(),
            'company_id' => $company->id,
            'status' => 'pending_payment',
            'currency' => 'USD',
            'total' => 100,
        ]);
        $invoice = Invoice::query()->create([
            'order_id' => $order->id,
            'total_amount' => 100.00,
            'currency' => 'usd',
            'status' => Invoice::STATUS_PENDING,
        ]);

        return Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => 100.00,
            'currency' => 'usd',
            'status' => $status,
            'payment_method' => $method,
            'reference_code' => 'pi_test_'.str()->uuid(),
        ]);
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}

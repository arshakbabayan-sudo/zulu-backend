<?php

namespace Tests\Feature\Packages\Saga;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PackageComponent;
use App\Models\Payment;
use App\Services\Packages\Saga\ComponentReserverRegistry;
use App\Services\Packages\Saga\Contracts\ComponentReserverInterface;
use App\Services\Packages\Saga\DTOs\ConfirmationResult;
use App\Services\Packages\Saga\DTOs\ReservationResult;
use App\Services\Packages\Saga\DTOs\RollbackResult;
use App\Services\Packages\Saga\PackageBookingOrchestrator;
use App\Services\Payments\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * INF-3 money-path coverage gap fill (Sprint 39).
 *
 * Covers the happy-path of saga refund automation: when a multi-component
 * package booking saga rolls back (some component fails to reserve) AND the
 * order has an already-PAID payment, the orchestrator triggers a real
 * gateway refund via PaymentService → PaymentGatewayService.
 *
 * Existing `SagaRefundOnRollbackTest` covers the negative paths
 * (no payment / payment-not-paid). This file completes the coverage with
 * the affirmative happy-path:
 *  - saga.status flips to "refunded"
 *  - saga.context.refund.status = "success"
 *  - payment.status flips to refunded
 *  - state log records refund_completed transition
 *
 * Together with PaymentRefundTest (low-level PaymentService coverage), this
 * provides end-to-end money-correctness assurance for the saga rollback path.
 */
class SagaRefundHappyPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_with_paid_payment_triggers_successful_refund(): void
    {
        // Force hotel reservation to fail so saga rolls back
        app(ComponentReserverRegistry::class)->register('hotel', new class implements ComponentReserverInterface
        {
            public function reserve($c, $i = null): ReservationResult
            {
                return ReservationResult::failure('inventory_unavailable');
            }

            public function confirm($c): ConfirmationResult
            {
                return ConfirmationResult::success();
            }

            public function rollback($c): RollbackResult
            {
                return RollbackResult::success();
            }

            public function serviceType(): string
            {
                return 'hotel';
            }
        });

        // Mock the payment gateway to return a successful refund
        $this->mock(PaymentGatewayService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refundPaymentIntent')
                ->once()
                ->andReturn([
                    'success' => true,
                    'refund_id' => 're_test_saga_'.bin2hex(random_bytes(4)),
                    'status' => 'succeeded',
                ]);
        });

        // Build package + order + paid payment
        $package = $this->makePackage(['hotel']);
        $order = $this->makeOrder($package, totalAmount: 250);
        $payment = $this->attachPaidPayment($order, amount: 250);

        // Run the orchestrator (will fail, rollback, refund)
        $saga = app(PackageBookingOrchestrator::class)->runForOrder($order->fresh());

        // Saga reached the refunded terminal state
        $this->assertSame('refunded', $saga->status);

        // Refund context records completion
        $context = $saga->context;
        $this->assertSame('success', $context['refund']['status']);
        $this->assertSame($payment->id, $context['refund']['payment_id']);

        // Payment row updated
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => Payment::STATUS_REFUNDED,
        ]);

        // State log records the refund_completed transition
        $this->assertDatabaseHas('saga_state_log', [
            'saga_id' => $saga->id,
            'event' => 'refund_completed',
        ]);
    }

    public function test_refund_amount_matches_payment_amount_invariant(): void
    {
        // Same setup as happy-path test, but assert the gateway is called
        // with the exact Payment row carrying the right amount + currency.
        app(ComponentReserverRegistry::class)->register('flight', new class implements ComponentReserverInterface
        {
            public function reserve($c, $i = null): ReservationResult
            {
                return ReservationResult::failure('schedule_conflict');
            }

            public function confirm($c): ConfirmationResult
            {
                return ConfirmationResult::success();
            }

            public function rollback($c): RollbackResult
            {
                return RollbackResult::success();
            }

            public function serviceType(): string
            {
                return 'flight';
            }
        });

        $expectedAmount = 487.50;
        $expectedCurrency = 'EUR';

        $this->mock(PaymentGatewayService::class, function (MockInterface $mock) use ($expectedAmount, $expectedCurrency): void {
            $mock->shouldReceive('refundPaymentIntent')
                ->once()
                ->withArgs(function (Payment $arg) use ($expectedAmount, $expectedCurrency): bool {
                    return abs((float) $arg->amount - $expectedAmount) < 0.001
                        && strtoupper((string) $arg->currency) === $expectedCurrency;
                })
                ->andReturn([
                    'success' => true,
                    'refund_id' => 're_test_amount_check',
                    'status' => 'succeeded',
                ]);
        });

        $package = $this->makePackage(['flight'], currency: $expectedCurrency, basePrice: $expectedAmount);
        $order = $this->makeOrder($package, totalAmount: $expectedAmount, currency: $expectedCurrency);
        $payment = $this->attachPaidPayment($order, amount: $expectedAmount, currency: $expectedCurrency);

        $saga = app(PackageBookingOrchestrator::class)->runForOrder($order->fresh());

        $this->assertSame('refunded', $saga->status);
        $this->assertSame('success', $saga->context['refund']['status']);
    }

    /**
     * @param  array<int, string>  $serviceTypes
     */
    private function makePackage(array $serviceTypes, string $currency = 'USD', float $basePrice = 250): Package
    {
        $company = Company::query()->create([
            'name' => 'Saga Refund Happy Co',
            'type' => 'operator',
        ]);

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'package',
            'title' => 'Test Package',
            'price' => $basePrice,
            'currency' => $currency,
            'status' => 'active',
        ]);

        $package = Package::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'package_type' => 'fixed',
            'package_title' => 'Test Package',
            'duration_days' => 3,
            'min_nights' => 2,
            'adults_count' => 1,
            'children_count' => 0,
            'infants_count' => 0,
            'base_price' => $basePrice,
            'display_price_mode' => 'total',
            'currency' => $currency,
            'is_public' => true,
            'is_bookable' => true,
            'is_package_eligible' => true,
            'status' => 'active',
        ]);

        foreach ($serviceTypes as $i => $type) {
            $subOffer = Offer::query()->create([
                'company_id' => $company->id,
                'type' => $type,
                'title' => $type,
                'price' => $basePrice / max(count($serviceTypes), 1),
                'currency' => $currency,
                'status' => 'active',
            ]);
            PackageComponent::query()->create([
                'package_id' => $package->id,
                'offer_id' => $subOffer->id,
                'module_type' => $type,
                'package_role' => $type,
                'service_type' => $type,
                'service_id' => $i + 1,
                'is_required' => true,
                'sort_order' => $i,
                'selection_mode' => 'fixed',
            ]);
        }

        return $package;
    }

    private function makeOrder(Package $package, float $totalAmount = 250, string $currency = 'USD'): Order
    {
        $order = Order::query()->create([
            'order_number' => 'ORD-SAGA-RFND-'.bin2hex(random_bytes(3)),
            'buyer_type' => 'client',
            'status' => 'paid',
            'currency' => $currency,
            'subtotal' => $totalAmount,
            'tax' => 0,
            'total' => $totalAmount,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'package',
            'item_id' => $package->id,
            'package_id' => $package->id,
            'quantity' => 1,
            'unit_price' => $totalAmount,
            'total' => $totalAmount,
            'currency' => $currency,
            'status' => 'pending',
        ]);

        return $order;
    }

    private function attachPaidPayment(Order $order, float $amount, string $currency = 'USD'): Payment
    {
        $invoice = Invoice::query()->create([
            'order_id' => $order->id,
            'unique_booking_reference' => 'REF-'.bin2hex(random_bytes(3)),
            'total_amount' => $amount,
            'currency' => $currency,
            'status' => Invoice::STATUS_ISSUED,
        ]);

        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => Payment::STATUS_PAID,
            'payment_method' => 'card',
            'reference_code' => 'pi_test_paid_'.bin2hex(random_bytes(4)),
        ]);

        $order->payment_id = $payment->id;
        $order->save();

        return $payment;
    }
}

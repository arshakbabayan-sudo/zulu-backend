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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SagaRefundOnRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_records_refund_skipped_when_no_payment_on_order(): void
    {
        // Force hotel reservation to fail
        app(ComponentReserverRegistry::class)->register('hotel', new class implements ComponentReserverInterface
        {
            public function reserve($c, $i = null): ReservationResult
            {
                return ReservationResult::failure('inventory');
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

        $package = $this->makePackage(['hotel']);
        $order = $this->makeOrder($package); // no payment_id

        $saga = app(PackageBookingOrchestrator::class)->runForOrder($order);

        $this->assertSame('rolled_back', $saga->status);
        $context = $saga->context;
        $this->assertSame('skipped', $context['refund']['status']);
        $this->assertSame('no_payment_on_order', $context['refund']['reason']);
    }

    public function test_rollback_records_refund_failed_when_payment_not_paid_state(): void
    {
        app(ComponentReserverRegistry::class)->register('hotel', new class implements ComponentReserverInterface
        {
            public function reserve($c, $i = null): ReservationResult
            {
                return ReservationResult::failure('inventory');
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

        // Create an invoice + payment in pending state — refund() will throw because not in paid state
        $package = $this->makePackage(['hotel']);
        $order = $this->makeOrder($package);

        $invoice = Invoice::query()->create([
            'order_id' => $order->id,
            'unique_booking_reference' => 'REF-'.str()->random(6),
            'total_amount' => 100,
            'currency' => 'USD',
            'status' => Invoice::STATUS_ISSUED,
        ]);
        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'currency' => 'USD',
            'status' => 'pending',
            'payment_method' => 'card',
            'reference_code' => 'pi_test_pending',
        ]);

        $order->payment_id = $payment->id;
        $order->save();

        $saga = app(PackageBookingOrchestrator::class)->runForOrder($order->fresh());

        $this->assertSame('rolled_back', $saga->status);
        $this->assertSame('failed', $saga->context['refund']['status']);
        $this->assertSame($payment->id, $saga->context['refund']['payment_id']);
    }

    /**
     * @param  array<int, string>  $serviceTypes
     */
    private function makePackage(array $serviceTypes): Package
    {
        $company = Company::query()->create(['name' => 'Saga Refund Co', 'type' => 'operator']);
        $offer = Offer::query()->create(['company_id' => $company->id, 'type' => 'package', 'title' => 'P', 'price' => 100, 'currency' => 'USD', 'status' => 'active']);
        $package = Package::query()->create([
            'offer_id' => $offer->id, 'company_id' => $company->id, 'package_type' => 'fixed', 'package_title' => 'P',
            'duration_days' => 3, 'min_nights' => 2, 'adults_count' => 1, 'children_count' => 0, 'infants_count' => 0,
            'base_price' => 100, 'display_price_mode' => 'total', 'currency' => 'USD',
            'is_public' => true, 'is_bookable' => true, 'is_package_eligible' => true, 'status' => 'active',
        ]);

        foreach ($serviceTypes as $i => $type) {
            $subOffer = Offer::query()->create(['company_id' => $company->id, 'type' => $type, 'title' => $type, 'price' => 50, 'currency' => 'USD', 'status' => 'active']);
            PackageComponent::query()->create([
                'package_id' => $package->id, 'offer_id' => $subOffer->id, 'module_type' => $type, 'package_role' => $type,
                'service_type' => $type, 'service_id' => $i + 1, 'is_required' => true, 'sort_order' => $i, 'selection_mode' => 'fixed',
            ]);
        }

        return $package;
    }

    private function makeOrder(Package $package): Order
    {
        $order = Order::query()->create([
            'order_number' => 'ORD-RFND-'.str()->random(6),
            'buyer_type' => 'client',
            'status' => 'paid',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'package',
            'item_id' => $package->id,
            'package_id' => $package->id,
            'quantity' => 1,
            'unit_price' => 100,
            'total' => 100,
            'currency' => 'USD',
            'status' => 'pending',
        ]);

        return $order;
    }
}

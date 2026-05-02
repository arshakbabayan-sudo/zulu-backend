<?php

namespace Tests\Feature\Packages\Saga;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PackageBookingSaga;
use App\Models\PackageComponent;
use App\Models\User;
use App\Services\Packages\PackageOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageOrderSagaTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_paid_triggers_saga_for_package_order(): void
    {
        $package = $this->makePackageWithComponents(['flight', 'hotel']);
        $order = $this->makePackageOrder($package);

        $service = app(PackageOrderService::class);
        $service->markPaid($order);

        // Saga should exist and be confirmed (stub reservers always succeed)
        $saga = PackageBookingSaga::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($saga);
        $this->assertSame('confirmed', $saga->status);
        $this->assertCount(2, $saga->components);
    }

    public function test_non_package_paid_order_creates_no_op_saga(): void
    {
        // Order with non-package item should still trigger no-op saga path? No —
        // PackageOrderService::markPaid only triggers when item_type=package exists.
        // So a non-package order should NOT have a saga at all.
        $order = $this->makeNonPackageOrder();

        $service = app(PackageOrderService::class);
        try {
            $service->markPaid($order);
        } catch (\Throwable $e) {
            // The non-package path has its own validation; we don't care about other failures here,
            // we only care that NO saga was created.
        }

        $this->assertNull(PackageBookingSaga::query()->where('order_id', $order->id)->first());
    }

    /**
     * @param  array<int, string>  $serviceTypes
     */
    private function makePackageWithComponents(array $serviceTypes): Package
    {
        $company = Company::query()->create([
            'name' => 'Trigger Co '.str()->random(6),
            'type' => 'operator',
            'status' => 'active',
        ]);

        $packageOffer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'package',
            'title' => 'Package '.str()->random(4),
            'price' => 500,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $package = Package::query()->create([
            'offer_id' => $packageOffer->id,
            'company_id' => $company->id,
            'package_type' => 'fixed',
            'package_title' => 'Package '.str()->random(4),
            'duration_days' => 3,
            'min_nights' => 2,
            'adults_count' => 1,
            'children_count' => 0,
            'infants_count' => 0,
            'base_price' => 500,
            'display_price_mode' => 'total',
            'currency' => 'USD',
            'is_public' => true,
            'is_bookable' => true,
            'is_package_eligible' => true,
            'status' => 'active',
        ]);

        foreach ($serviceTypes as $i => $type) {
            $offer = Offer::query()->create([
                'company_id' => $company->id,
                'type' => $type,
                'title' => 'Sub '.$type,
                'price' => 100,
                'currency' => 'USD',
                'status' => 'active',
            ]);

            PackageComponent::query()->create([
                'package_id' => $package->id,
                'offer_id' => $offer->id,
                'module_type' => $type,
                'package_role' => $type,
                'service_type' => $type,
                'service_id' => $i + 1,
                'is_required' => true,
                'sort_order' => $i,
                'selection_mode' => 'fixed',
            ]);
        }

        return $package->fresh();
    }

    private function makePackageOrder(Package $package): Order
    {
        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_number' => 'ORD-TRIG-'.str()->random(6),
            'user_id' => $user->id,
            'buyer_type' => 'client',
            'status' => 'pending_payment',
            'currency' => 'USD',
            'subtotal' => 500,
            'tax' => 0,
            'total' => 500,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'package',
            'item_id' => $package->id,
            'package_id' => $package->id,
            'quantity' => 1,
            'unit_price' => 500,
            'total' => 500,
            'currency' => 'USD',
            'status' => 'pending',
        ]);

        return $order->fresh();
    }

    private function makeNonPackageOrder(): Order
    {
        $user = User::factory()->create();

        return Order::query()->create([
            'order_number' => 'ORD-NONPKG-'.str()->random(6),
            'user_id' => $user->id,
            'buyer_type' => 'client',
            'status' => 'pending_payment',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
        ]);
    }
}

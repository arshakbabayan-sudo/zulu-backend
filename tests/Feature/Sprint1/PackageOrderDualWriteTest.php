<?php

namespace Tests\Feature\Sprint1;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PackageComponent;
use App\Models\PackageOrder;
use App\Models\PackageOrderItem;
use App\Models\User;
use App\Services\Commissions\CommissionService;
use App\Services\Finance\FinanceService;
use App\Services\Invoices\InvoiceService;
use App\Services\Notifications\NotificationService;
use App\Services\Orders\OrderService;
use App\Services\Packages\PackageOrderService;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageOrderDualWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_order_dual_writes_package_order_and_order_rows(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();

        $packageOffer = $this->createOffer($company, 'package', 200.00, 'USD');
        $flightOffer = $this->createOffer($company, 'flight', 120.00, 'USD');
        $hotelOffer = $this->createOffer($company, 'hotel', 80.00, 'USD');

        $package = Package::query()->create([
            'offer_id' => $packageOffer->id,
            'company_id' => $company->id,
            'package_type' => 'fixed',
            'package_title' => 'Sprint 1 Package',
            'currency' => 'USD',
            'is_public' => true,
            'is_bookable' => false,
            'status' => 'active',
        ]);

        PackageComponent::query()->create([
            'package_id' => $package->id,
            'offer_id' => $flightOffer->id,
            'module_type' => 'flight',
            'package_role' => 'flight',
            'is_required' => true,
            'sort_order' => 1,
            'selection_mode' => 'fixed',
            'price_override' => 120.00,
        ]);

        PackageComponent::query()->create([
            'package_id' => $package->id,
            'offer_id' => $hotelOffer->id,
            'module_type' => 'hotel',
            'package_role' => 'stay',
            'is_required' => true,
            'sort_order' => 2,
            'selection_mode' => 'fixed',
            'price_override' => 80.00,
        ]);

        $service = new PackageOrderService(
            app(InvoiceService::class),
            app(PaymentService::class),
            app(CommissionService::class),
            app(NotificationService::class),
            app(FinanceService::class),
            app(OrderService::class)
        );

        $packageOrder = $service->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);

        $this->assertSame(1, PackageOrder::count());
        $this->assertSame(2, PackageOrderItem::count());
        $this->assertSame('draft', $packageOrder->status);

        $packageOrder->refresh();
        $order = Order::query()->firstOrFail();

        $this->assertSame($order->id, $packageOrder->mirror_order_id);
        $this->assertSame($packageOrder->order_number, $order->order_number);
        $this->assertSame('cart', $order->status);
        $this->assertSame('package_order', $order->metadata['legacy_origin'] ?? null);
        $this->assertSame($packageOrder->id, $order->metadata['legacy_package_order_id'] ?? null);

        $orderItems = OrderItem::query()->where('order_id', $order->id)->get();
        $this->assertCount(2, $orderItems);
        $this->assertSame(
            ['flight', 'hotel'],
            $orderItems->pluck('item_type')->sort()->values()->all()
        );
        $this->assertSame(
            [$package->id],
            $orderItems->pluck('package_id')->unique()->values()->all()
        );
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Package Dual Write Co '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Package Dual Write User',
            'email' => 'package-dual-write-'.str()->uuid().'@example.test',
            'password' => 'password',
        ]);
    }

    private function createOffer(Company $company, string $type, float $price, string $currency): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => $type,
            'title' => strtoupper($type).' Offer '.str()->uuid(),
            'price' => $price,
            'currency' => $currency,
            'status' => Offer::STATUS_PUBLISHED,
        ]);
    }
}

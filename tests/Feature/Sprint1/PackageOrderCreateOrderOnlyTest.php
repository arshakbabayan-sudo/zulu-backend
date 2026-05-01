<?php

namespace Tests\Feature\Sprint1;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PackageComponent;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Packages\PackageOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery\MockInterface;
use Tests\TestCase;

class PackageOrderCreateOrderOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_order_returns_order_and_writes_order_items_only(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $package = $this->createPackageWithComponents($company);

        $order = app(PackageOrderService::class)->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame('package_order', $order->metadata['legacy_origin'] ?? null);
        $this->assertCount(2, $order->items);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(2, OrderItem::query()->count());
    }

    public function test_create_order_rolls_back_when_order_service_fails(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $package = $this->createPackageWithComponents($company);

        $this->mock(OrderService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('create')
                ->once()
                ->andThrow(new InvalidArgumentException('forced package order creation failure'));
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('forced package order creation failure');

        try {
            app(PackageOrderService::class)->createOrder($package, $user, [
                'booking_channel' => 'public_b2c',
                'adults_count' => 1,
            ]);
        } finally {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(0, OrderItem::query()->count());
        }
    }

    private function createPackageWithComponents(Company $company): Package
    {
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
            'is_bookable' => true,
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

        return $package;
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Package Create Order Only Co '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Package Create Order Only User',
            'email' => 'package-create-order-only-'.str()->uuid().'@example.test',
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

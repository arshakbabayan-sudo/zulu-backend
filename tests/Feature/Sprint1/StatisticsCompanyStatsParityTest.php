<?php

namespace Tests\Feature\Sprint1;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageComponent;
use App\Models\User;
use App\Services\Analytics\StatisticsService;
use App\Services\Bookings\BookingService;
use App\Services\Commissions\CommissionService;
use App\Services\Finance\FinanceService;
use App\Services\Invoices\InvoiceService;
use App\Services\Notifications\NotificationService;
use App\Services\Orders\OrderService;
use App\Services\Packages\PackageOrderService;
use App\Services\Payments\PaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsCompanyStatsParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_bookings_counts_confirmed_and_excludes_cancelled(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 'flight', 125.00);
        $bookingService = app(BookingService::class);

        for ($i = 0; $i < 3; $i++) {
            $order = $this->createBookingFlow($bookingService, $company, $user, $offer, 125.00);
            $bookingService->confirm($order->fresh());
        }

        $cancelledOrder = $this->createBookingFlow($bookingService, $company, $user, $offer, 125.00);
        $bookingService->cancel($cancelledOrder->fresh());

        $stats = app(StatisticsService::class)->getCompanyStats($company->id, []);

        $this->assertSame(3, $stats['total_bookings']);
    }

    public function test_service_type_filter_uses_order_item_type(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $packageService = $this->makePackageOrderService();

        $flightPackage = $this->createPackageWithComponents($company, ['flight', 'hotel'], 'Flight Mix');
        $hotelPackage = $this->createPackageWithComponents($company, ['hotel'], 'Hotel Only');

        $flightOrder = $packageService->createOrder($flightPackage, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);
        $packageService->markPaid($flightOrder->fresh());

        $hotelOrder = $packageService->createOrder($hotelPackage, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);
        $packageService->markPaid($hotelOrder->fresh());

        $stats = app(StatisticsService::class)->getCompanyStats($company->id, [
            'service_type' => 'flight',
        ]);

        $this->assertSame(1, $stats['total_bookings']);
    }

    public function test_date_filter_narrows_total_bookings_from_order_created_at(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 'flight', 140.00);
        $bookingService = app(BookingService::class);
        $now = Carbon::now();

        $offsets = [-1, -2, 0, -15, -20];
        foreach ($offsets as $offset) {
            $order = $this->createBookingFlow($bookingService, $company, $user, $offer, 140.00);
            $bookingService->confirm($order->fresh());

            $timestamp = $now->copy()->addDays($offset);
            Order::query()
                ->whereKey($order->id)
                ->update([
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
        }

        $stats = app(StatisticsService::class)->getCompanyStats($company->id, [
            'date_from' => $now->copy()->subDays(2)->toDateString(),
            'date_to' => $now->copy()->toDateString(),
        ]);

        $this->assertSame(3, $stats['total_bookings']);
    }

    public function test_seeded_orders_keep_legacy_origin_metadata(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();

        $bookingOffer = $this->createOffer($company, 'flight', 100.00);
        $bookingService = app(BookingService::class);
        $order = $this->createBookingFlow($bookingService, $company, $user, $bookingOffer, 100.00);
        $bookingService->confirm($order->fresh());

        $packageService = $this->makePackageOrderService();
        $package = $this->createPackageWithComponents($company, ['flight', 'hotel'], 'Origin Check');
        $packageOrder = $packageService->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);
        $packageService->markPaid($packageOrder->fresh());

        $orders = Order::query()
            ->where('company_id', $company->id)
            ->get();

        $this->assertCount(2, $orders);
        $this->assertTrue(
            $orders->every(fn (Order $order): bool => isset($order->metadata['legacy_origin']))
        );
        $this->assertEqualsCanonicalizing(
            ['booking', 'package_order'],
            $orders->map(fn (Order $order): ?string => $order->metadata['legacy_origin'] ?? null)->all()
        );
    }

    private function createBookingFlow(
        BookingService $bookingService,
        Company $company,
        User $user,
        Offer $offer,
        float $price
    ): Order {
        return $bookingService->create(
            [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'status' => 'pending_payment',
                'currency' => 'USD',
            ],
            [
                ['offer_id' => $offer->id, 'price' => $price],
            ],
            []
        );
    }

    private function makePackageOrderService(): PackageOrderService
    {
        return new PackageOrderService(
            app(InvoiceService::class),
            app(PaymentService::class),
            app(CommissionService::class),
            app(NotificationService::class),
            app(FinanceService::class),
            app(OrderService::class)
        );
    }

    /**
     * @param  list<string>  $componentTypes
     */
    private function createPackageWithComponents(Company $company, array $componentTypes, string $label): Package
    {
        $packageOffer = $this->createOffer($company, 'package', 300.00);
        $package = Package::query()->create([
            'offer_id' => $packageOffer->id,
            'company_id' => $company->id,
            'package_type' => 'fixed',
            'package_title' => $label.' '.str()->uuid(),
            'currency' => 'USD',
            'is_public' => true,
            'is_bookable' => true,
            'status' => 'active',
        ]);

        foreach (array_values($componentTypes) as $index => $componentType) {
            $componentOffer = $this->createOffer($company, $componentType, 100.00 + ($index * 20));
            PackageComponent::query()->create([
                'package_id' => $package->id,
                'offer_id' => $componentOffer->id,
                'module_type' => $componentType,
                'package_role' => $componentType,
                'is_required' => true,
                'sort_order' => $index + 1,
                'selection_mode' => 'fixed',
                'price_override' => 100.00 + ($index * 20),
            ]);
        }

        return $package;
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Statistics Parity Co '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Statistics Parity User',
            'email' => 'statistics-parity-'.str()->uuid().'@example.test',
            'password' => 'password',
        ]);
    }

    private function createOffer(Company $company, string $type, float $price): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => $type,
            'title' => strtoupper($type).' Offer '.str()->uuid(),
            'price' => $price,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
    }
}

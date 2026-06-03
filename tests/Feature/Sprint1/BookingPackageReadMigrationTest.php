<?php

namespace Tests\Feature\Sprint1;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageComponent;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Packages\PackageOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingPackageReadMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_service_list_for_companies_returns_booking_origin_orders_only(): void
    {
        $bookingService = app(BookingService::class);
        $packageOrderService = app(PackageOrderService::class);

        $companyA = $this->createCompany();
        $companyB = $this->createCompany();
        $user = $this->createUser();

        $offerA = $this->createOffer($companyA, 'flight', 100.00);
        $offerB = $this->createOffer($companyB, 'flight', 130.00);

        $this->createBookingFlow($bookingService, $companyA, $user, $offerA, 100.00);
        $this->createBookingFlow($bookingService, $companyA, $user, $offerA, 120.00);
        $this->createBookingFlow($bookingService, $companyB, $user, $offerB, 130.00);

        $package = $this->createPackageWithComponents($companyA, ['hotel'], 'Read Migration Package A');
        $packageOrderService->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);

        $orders = $bookingService->listForCompanies([$companyA->id]);

        $this->assertCount(2, $orders);
        $this->assertTrue($orders->every(function (Order $order): bool {
            return ($order->metadata['legacy_origin'] ?? null) === 'booking';
        }));
    }

    public function test_booking_service_paginate_for_companies_happy_path(): void
    {
        $bookingService = app(BookingService::class);
        $packageOrderService = app(PackageOrderService::class);

        $companyA = $this->createCompany();
        $companyB = $this->createCompany();
        $user = $this->createUser();

        $offerA = $this->createOffer($companyA, 'flight', 140.00);
        $offerB = $this->createOffer($companyB, 'flight', 170.00);

        $this->createBookingFlow($bookingService, $companyA, $user, $offerA, 140.00);
        $this->createBookingFlow($bookingService, $companyA, $user, $offerA, 150.00);
        $this->createBookingFlow($bookingService, $companyB, $user, $offerB, 170.00);

        $package = $this->createPackageWithComponents($companyA, ['transfer'], 'Read Migration Package B');
        $packageOrderService->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);

        $page = $bookingService->paginateForCompanies([$companyA->id], 10);

        $this->assertSame(2, $page->total());
    }

    public function test_booking_service_get_with_details_finds_by_legacy_booking_id(): void
    {
        $bookingService = app(BookingService::class);

        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 'flight', 190.00);

        $booking = $this->createBookingFlow($bookingService, $company, $user, $offer, 190.00, legacyBookingId: 7001);

        $order = $bookingService->getWithDetails(7001);

        $this->assertNotNull($order);
        $this->assertSame(7001, (int) ($order->metadata['legacy_booking_id'] ?? 0));
        $this->assertNull($bookingService->getWithDetails(999999));
    }

    public function test_package_order_service_list_for_user_returns_package_origin_only(): void
    {
        $bookingService = app(BookingService::class);
        $packageOrderService = app(PackageOrderService::class);

        $company = $this->createCompany();
        $userX = $this->createUser();
        $userY = $this->createUser();

        $bookingOffer = $this->createOffer($company, 'flight', 210.00);
        $this->createBookingFlow($bookingService, $company, $userX, $bookingOffer, 210.00);

        $packageX = $this->createPackageWithComponents($company, ['hotel'], 'User X Package');
        $packageY = $this->createPackageWithComponents($company, ['transfer'], 'User Y Package');
        $packageOrderService->createOrder($packageX, $userX, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);
        $packageOrderService->createOrder($packageY, $userY, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);

        $page = $packageOrderService->listForUser($userX, 10);

        $this->assertSame(1, $page->total());
    }

    public function test_package_order_service_find_for_user_finds_by_legacy_id(): void
    {
        $packageOrderService = app(PackageOrderService::class);

        $company = $this->createCompany();
        $owner = $this->createUser();
        $otherUser = $this->createUser();

        $package = $this->createPackageWithComponents($company, ['hotel', 'transfer'], 'Find By User Package');
        $legacyOrder = $packageOrderService->createOrder($package, $owner, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);
        $legacyOrder->metadata = array_merge($legacyOrder->metadata ?? [], ['legacy_package_order_id' => 8001]);
        $legacyOrder->save();

        $order = $packageOrderService->findForUser(8001, $owner);

        $this->assertNotNull($order);
        $this->assertSame(8001, (int) ($order->metadata['legacy_package_order_id'] ?? 0));
        $this->assertNull($packageOrderService->findForUser(8001, $otherUser));
    }

    public function test_package_order_service_find_for_company_scope_happy_and_miss(): void
    {
        $packageOrderService = app(PackageOrderService::class);

        $companyA = $this->createCompany();
        $companyB = $this->createCompany();
        $user = $this->createUser();

        $package = $this->createPackageWithComponents($companyA, ['excursion'], 'Company Scope Package');
        $legacyOrder = $packageOrderService->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);
        $legacyOrder->metadata = array_merge($legacyOrder->metadata ?? [], ['legacy_package_order_id' => 8002]);
        $legacyOrder->save();

        $inScope = $packageOrderService->findForCompanyScope(8002, [$companyA->id]);
        $outOfScope = $packageOrderService->findForCompanyScope(8002, [$companyB->id]);

        $this->assertNotNull($inScope);
        $this->assertSame(8002, (int) ($inScope->metadata['legacy_package_order_id'] ?? 0));
        $this->assertNull($outOfScope);
    }

    private function createBookingFlow(
        BookingService $bookingService,
        Company $company,
        User $user,
        Offer $offer,
        float $price,
        ?int $legacyBookingId = null
    ): Order {
        $order = $bookingService->create(
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

        if ($legacyBookingId !== null) {
            $order->metadata = array_merge($order->metadata ?? [], ['legacy_booking_id' => $legacyBookingId]);
            $order->save();
        }

        return $order->fresh();
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
            'name' => 'Booking Package Read Co '.str()->uuid(),
            'type' => 'operator',
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Booking Package Read User',
            'email' => 'booking-package-read-'.str()->uuid().'@example.test',
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

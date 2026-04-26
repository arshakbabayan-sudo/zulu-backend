<?php

namespace Tests\Unit\Services\Finance;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Offer;
use App\Models\Package;
use App\Models\PackageOrder;
use App\Models\PackageOrderItem;
use App\Models\SupplierEntitlement;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_entitlements_for_booking_happy_path_percentage_rule(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 'flight');
        $booking = $this->createBooking($company, $user, 'USD', 100.00);
        $item = $this->createBookingItem($booking, $offer, 100.00);

        $this->createRule([
            'type' => 'percentage',
            'level' => 'seller',
            'scope_id' => $company->id,
            'service_type' => 'flight',
            'percentage_value' => 10.0,
        ]);

        $created = app(FinanceService::class)->createEntitlementsForBooking($booking);

        $this->assertCount(1, $created);

        $entitlement = SupplierEntitlement::query()->where('booking_item_id', $item->id)->firstOrFail();
        $this->assertSame($booking->id, $entitlement->booking_id);
        $this->assertSame($company->id, $entitlement->company_id);
        $this->assertSame('flight', $entitlement->service_type);
        $this->assertEqualsWithDelta(100.0, (float) $entitlement->gross_amount, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $entitlement->commission_amount, 0.0001);
        $this->assertEqualsWithDelta(90.0, (float) $entitlement->net_amount, 0.0001);
    }

    public function test_create_entitlements_for_order_happy_path_creates_row_per_item(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $packageOffer = $this->createOffer($company, 'package');
        $moduleOfferA = $this->createOffer($company, 'flight');
        $moduleOfferB = $this->createOffer($company, 'hotel');
        $package = $this->createPackage($company, $packageOffer);
        $order = $this->createPackageOrder($package, $company, $user, 'USD');

        $itemA = $this->createPackageOrderItem($order, $moduleOfferA, $company->id, 'flight', 80.00, 1);
        $itemB = $this->createPackageOrderItem($order, $moduleOfferB, $company->id, 'hotel', 120.00, 2);

        $this->createRule([
            'type' => 'percentage',
            'level' => 'seller',
            'scope_id' => $company->id,
            'service_type' => null,
            'percentage_value' => 10.0,
        ]);

        $created = app(FinanceService::class)->createEntitlementsForOrder($order);

        $this->assertCount(2, $created);
        $this->assertSame(2, SupplierEntitlement::query()->where('package_order_id', $order->id)->count());
        $this->assertNotNull(SupplierEntitlement::query()->where('package_order_item_id', $itemA->id)->first());
        $this->assertNotNull(SupplierEntitlement::query()->where('package_order_item_id', $itemB->id)->first());
    }

    public function test_create_entitlements_for_booking_is_idempotent_and_reuses_existing_rows(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 'flight');
        $booking = $this->createBooking($company, $user, 'USD', 100.00);
        $this->createBookingItem($booking, $offer, 100.00);

        $this->createRule([
            'type' => 'percentage',
            'level' => 'seller',
            'scope_id' => $company->id,
            'service_type' => 'flight',
            'percentage_value' => 10.0,
        ]);

        $first = app(FinanceService::class)->createEntitlementsForBooking($booking);
        $second = app(FinanceService::class)->createEntitlementsForBooking($booking);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame($first[0]->id, $second[0]->id);
        $this->assertSame(1, SupplierEntitlement::query()->where('booking_id', $booking->id)->count());
    }

    public function test_create_entitlements_for_booking_without_rule_sets_zero_commission_and_net_equals_gross(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 'flight');
        $booking = $this->createBooking($company, $user, 'USD', 125.00);
        $item = $this->createBookingItem($booking, $offer, 125.00);

        $created = app(FinanceService::class)->createEntitlementsForBooking($booking);

        $this->assertCount(1, $created);

        $entitlement = SupplierEntitlement::query()->where('booking_item_id', $item->id)->firstOrFail();
        $this->assertEqualsWithDelta(125.0, (float) $entitlement->gross_amount, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $entitlement->commission_amount, 0.0001);
        $this->assertEqualsWithDelta(125.0, (float) $entitlement->net_amount, 0.0001);
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Finance Service Seller '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Finance Test User',
            'email' => 'finance-'.str()->uuid().'@example.test',
            'password' => 'password',
        ]);
    }

    private function createOffer(Company $company, string $type): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => $type,
            'title' => strtoupper($type).' Offer '.str()->uuid(),
            'price' => 100.00,
            'currency' => 'USD',
            'status' => 'active',
        ]);
    }

    private function createBooking(Company $company, User $user, string $currency, float $totalPrice): Booking
    {
        return Booking::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'total_price' => $totalPrice,
            'currency' => $currency,
        ]);
    }

    private function createBookingItem(Booking $booking, Offer $offer, float $price): BookingItem
    {
        return BookingItem::query()->create([
            'booking_id' => $booking->id,
            'offer_id' => $offer->id,
            'price' => $price,
        ]);
    }

    private function createPackage(Company $company, Offer $packageOffer): Package
    {
        return Package::query()->create([
            'offer_id' => $packageOffer->id,
            'company_id' => $company->id,
            'package_type' => 'fixed',
            'status' => 'active',
        ]);
    }

    private function createPackageOrder(Package $package, Company $company, User $user, string $currency): PackageOrder
    {
        return PackageOrder::query()->create([
            'package_id' => $package->id,
            'user_id' => $user->id,
            'company_id' => $company->id,
            'order_number' => 'PO-'.str()->upper(str()->random(12)),
            'booking_channel' => 'public_b2c',
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'adults_count' => 1,
            'children_count' => 0,
            'infants_count' => 0,
            'currency' => $currency,
            'base_component_total_snapshot' => 200.00,
            'discount_snapshot' => 0,
            'markup_snapshot' => 0,
            'addon_total_snapshot' => 0,
            'final_total_snapshot' => 200.00,
            'display_price_mode_snapshot' => 'total',
            'notes' => null,
        ]);
    }

    private function createPackageOrderItem(
        PackageOrder $order,
        Offer $offer,
        int $companyId,
        string $moduleType,
        float $price,
        int $sortOrder
    ): PackageOrderItem {
        return PackageOrderItem::query()->create([
            'package_order_id' => $order->id,
            'package_component_id' => null,
            'offer_id' => $offer->id,
            'module_type' => $moduleType,
            'package_role' => $moduleType,
            'company_id' => $companyId,
            'is_required' => true,
            'price_snapshot' => $price,
            'currency_snapshot' => 'USD',
            'status' => 'pending',
            'failure_reason' => null,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRule(array $overrides = []): CommissionRule
    {
        return CommissionRule::query()->create(array_merge([
            'type' => 'percentage',
            'level' => 'global',
            'scope_id' => null,
            'service_type' => 'flight',
            'percentage_value' => 5.0,
            'fixed_value' => null,
            'fixed_currency' => null,
            'hybrid_config' => null,
            'tiered_config' => null,
            'direction' => 'zulu_from_seller',
            'priority' => 0,
            'effective_from' => now()->subDay(),
            'effective_to' => null,
            'status' => 'active',
            'active' => true,
            'notes' => 'finance service test rule',
            'created_by' => null,
        ], $overrides));
    }
}

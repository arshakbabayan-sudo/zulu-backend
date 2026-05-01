<?php

namespace Tests\Feature\Sprint1;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageComponent;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Commissions\CommissionService;
use App\Services\Finance\FinanceService;
use App\Services\Invoices\InvoiceService;
use App\Services\Notifications\NotificationService;
use App\Services\Orders\OrderService;
use App\Services\Packages\PackageOrderService;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceServiceOrderLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_for_order_persists_order_link_and_defaults(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 'flight', 100.00);
        $order = $this->createBookingFlow(app(BookingService::class), $company, $user, $offer, 100.00);

        $invoice = app(InvoiceService::class)->createForOrder($order, ['total_amount' => 100]);

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertSame($order->id, $invoice->order_id);
        $this->assertNotNull($invoice->order_id);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'order_id' => $order->id,
        ]);
    }

    public function test_create_for_order_uses_order_defaults_when_payload_is_empty(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 'flight', 50.00);
        $order = $this->createBookingFlow(app(BookingService::class), $company, $user, $offer, 50.00);

        $invoice = app(InvoiceService::class)->createForOrder($order);

        $this->assertSame($order->id, $invoice->order_id);
        $this->assertSame((string) $order->total, (string) $invoice->total_amount);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'order_id' => $order->id,
        ]);
    }

    public function test_create_for_order_from_package_flow_populates_order_id(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $packageOrder = $this->createPackageOrder($company, $user);
        $reference = 'PKG-INV-TEST-'.str()->uuid();
        $invoice = app(InvoiceService::class)->createForOrder($packageOrder, [
            'total_amount' => 200,
            'unique_booking_reference' => $reference,
        ]);

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertSame($packageOrder->id, $invoice->order_id);
        $this->assertNotNull($invoice->order_id);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'order_id' => $packageOrder->id,
        ]);
    }

    public function test_list_for_companies_filters_via_order_relation_and_returns_empty_for_empty_company_ids(): void
    {
        $invoiceService = app(InvoiceService::class);

        $companyA = $this->createCompany();
        $companyB = $this->createCompany();
        $user = $this->createUser();

        $bookingOffer = $this->createOffer($companyA, 'flight', 120.00);
        $booking = $this->createBookingFlow(app(BookingService::class), $companyA, $user, $bookingOffer, 120.00);
        $invoiceA = $invoiceService->createForOrder($booking, ['total_amount' => 120]);

        $packageOrderB = $this->createPackageOrder($companyB, $user);
        $invoiceB = $invoiceService->createForOrder($packageOrderB, [
            'total_amount' => 180,
            'unique_booking_reference' => 'PKG-INV-LIST-'.str()->uuid(),
        ]);

        $companyAInvoices = $invoiceService->listForCompanies([$companyA->id]);

        $this->assertCount(1, $companyAInvoices);
        $this->assertSame($invoiceA->id, $companyAInvoices->first()->id);
        $this->assertNotSame($invoiceB->id, $companyAInvoices->first()->id);
        $this->assertCount(0, $invoiceService->listForCompanies([]));
    }

    public function test_paginate_for_companies_filters_via_order_relation(): void
    {
        $invoiceService = app(InvoiceService::class);
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();
        $user = $this->createUser();

        $bookingOffer = $this->createOffer($companyA, 'flight', 130.00);
        $booking = $this->createBookingFlow(app(BookingService::class), $companyA, $user, $bookingOffer, 130.00);
        $invoiceA = $invoiceService->createForOrder($booking, ['total_amount' => 130]);

        $packageOrderB = $this->createPackageOrder($companyB, $user);
        $invoiceService->createForOrder($packageOrderB, [
            'total_amount' => 210,
            'unique_booking_reference' => 'PKG-INV-PAGE-'.str()->uuid(),
        ]);

        $page = $invoiceService->paginateForCompanies([$companyA->id]);

        $this->assertSame(1, $page->total());
        $this->assertCount(1, $page->items());
        $this->assertSame($invoiceA->id, $page->items()[0]->id);
    }

    public function test_list_for_companies_booking_id_filter_uses_order_metadata_lookup(): void
    {
        $invoiceService = app(InvoiceService::class);
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 'flight', 140.00);
        $bookingService = app(BookingService::class);

        $booking1 = $this->createBookingFlow($bookingService, $company, $user, $offer, 140.00, legacyBookingId: 9001);
        $invoiceService->createForOrder($booking1, ['total_amount' => 140]);

        $booking2 = $this->createBookingFlow($bookingService, $company, $user, $offer, 160.00, legacyBookingId: 9002);
        $invoice2 = $invoiceService->createForOrder($booking2, ['total_amount' => 160]);

        $results = $invoiceService->listForCompanies([$company->id], bookingId: 9002);

        $this->assertCount(1, $results);
        $this->assertSame($invoice2->id, $results->first()->id);
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
            $metadata = $order->metadata ?? [];
            $metadata['legacy_booking_id'] = $legacyBookingId;
            $order->metadata = $metadata;
            $order->save();
        }

        return $order->fresh();
    }

    private function createPackageOrder(Company $company, User $user): Order
    {
        $packageService = $this->makePackageOrderService();
        $package = $this->createPackageWithComponents($company, ['hotel'], 'Invoice Link Package');

        return $packageService->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);
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
            'name' => 'Invoice Link Co '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Invoice Link User',
            'email' => 'invoice-link-'.str()->uuid().'@example.test',
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

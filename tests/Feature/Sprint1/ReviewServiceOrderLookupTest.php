<?php

namespace Tests\Feature\Sprint1;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageComponent;
use App\Models\PackageOrder;
use App\Models\Review;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Commissions\CommissionService;
use App\Services\Finance\FinanceService;
use App\Services\Invoices\InvoiceService;
use App\Services\Notifications\NotificationService;
use App\Services\Orders\OrderService;
use App\Services\Packages\PackageOrderService;
use App\Services\Payments\PaymentService;
use App\Services\Reviews\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReviewServiceOrderLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_origin_happy_path_creates_review_with_legacy_booking_column(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 'flight', 125.00);
        $bookingService = app(BookingService::class);
        $order = $this->createBookingFlow($bookingService, $company, $user, $offer, 125.00);
        $bookingService->confirm($order->fresh());
        $legacyBookingId = (int) ($order->metadata['legacy_booking_id'] ?? 0);

        $review = app(ReviewService::class)->createReview($user, [
            'target_entity_type' => 'flight',
            'target_entity_id' => 101,
            'rating' => 8,
            'review_text' => 'Great trip',
            'booking_id' => $legacyBookingId,
        ]);

        $this->assertInstanceOf(Review::class, $review);
        $this->assertSame($legacyBookingId, $review->booking_id);
        $this->assertNull($review->package_order_id);
        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'booking_id' => $legacyBookingId,
            'package_order_id' => null,
            'user_id' => $user->id,
        ]);
    }

    public function test_package_order_origin_happy_path_creates_review_with_legacy_package_order_column(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $packageService = $this->makePackageOrderService();
        $package = $this->createPackageWithComponents($company, ['flight', 'hotel'], 'Review Package');
        $order = $packageService->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);
        $packageService->markPaid($order->fresh());
        $legacyPackageOrder = $this->createLegacyPackageOrder($package, $user, $company, 5001);
        $order->metadata = array_merge($order->metadata ?? [], ['legacy_package_order_id' => $legacyPackageOrder->id]);
        $order->save();

        $review = app(ReviewService::class)->createReview($user, [
            'target_entity_type' => 'package',
            'target_entity_id' => 202,
            'rating' => 9,
            'review_text' => 'Worth it',
            'package_order_id' => $legacyPackageOrder->id,
        ]);

        $this->assertInstanceOf(Review::class, $review);
        $this->assertSame($legacyPackageOrder->id, $review->package_order_id);
        $this->assertNull($review->booking_id);
        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'package_order_id' => $legacyPackageOrder->id,
            'booking_id' => null,
            'user_id' => $user->id,
        ]);
    }

    public function test_booking_lookup_rejects_when_booking_belongs_to_another_user(): void
    {
        $company = $this->createCompany();
        $owner = $this->createUser();
        $otherUser = $this->createUser();
        $offer = $this->createOffer($company, 'flight', 99.00);
        $bookingService = app(BookingService::class);
        $order = $this->createBookingFlow($bookingService, $company, $owner, $offer, 99.00);
        $bookingService->confirm($order->fresh());

        $this->assertReviewValidationError(
            $otherUser,
            [
                'target_entity_type' => 'flight',
                'target_entity_id' => 303,
                'rating' => 7,
                'booking_id' => (int) ($order->metadata['legacy_booking_id'] ?? 0),
            ],
            'booking_id'
        );
    }

    public function test_booking_lookup_rejects_when_mirror_order_status_is_not_confirmed(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 'flight', 110.00);
        $bookingService = app(BookingService::class);
        $order = $this->createBookingFlow($bookingService, $company, $user, $offer, 110.00);

        $this->assertReviewValidationError(
            $user,
            [
                'target_entity_type' => 'flight',
                'target_entity_id' => 404,
                'rating' => 6,
                'booking_id' => (int) ($order->metadata['legacy_booking_id'] ?? 0),
            ],
            'booking_id'
        );
    }

    public function test_package_order_lookup_rejects_when_mirror_order_status_is_not_paid(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $packageService = $this->makePackageOrderService();
        $package = $this->createPackageWithComponents($company, ['hotel'], 'Pending Package');
        $order = $packageService->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);
        $legacyPackageOrder = $this->createLegacyPackageOrder($package, $user, $company, 5002);
        $order->metadata = array_merge($order->metadata ?? [], ['legacy_package_order_id' => $legacyPackageOrder->id]);
        $order->save();

        $this->assertReviewValidationError(
            $user,
            [
                'target_entity_type' => 'package',
                'target_entity_id' => 505,
                'rating' => 5,
                'package_order_id' => $legacyPackageOrder->id,
            ],
            'package_order_id'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertReviewValidationError(User $user, array $payload, string $errorKey): void
    {
        try {
            app(ReviewService::class)->createReview($user, $payload);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }
    }

    private function createBookingFlow(
        BookingService $bookingService,
        Company $company,
        User $user,
        Offer $offer,
        float $price
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

        $legacyBooking = Booking::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => Booking::STATUS_PENDING,
            'total_price' => $price,
            'currency' => 'USD',
        ]);

        $metadata = $order->metadata ?? [];
        $metadata['legacy_booking_id'] = $legacyBooking->id;
        $order->metadata = $metadata;
        $order->save();

        return $order->fresh();
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
            'name' => 'Review Lookup Co '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Review Lookup User',
            'email' => 'review-lookup-'.str()->uuid().'@example.test',
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

    private function createLegacyPackageOrder(Package $package, User $user, Company $company, int $seedId): PackageOrder
    {
        return PackageOrder::query()->create([
            'package_id' => $package->id,
            'user_id' => $user->id,
            'company_id' => $company->id,
            'order_number' => 'PKG-LEGACY-'.$seedId,
            'booking_channel' => 'public_b2c',
            'status' => 'paid',
            'payment_status' => 'paid',
            'adults_count' => 1,
            'children_count' => 0,
            'infants_count' => 0,
            'currency' => 'USD',
            'base_component_total_snapshot' => 100.00,
            'discount_snapshot' => 0,
            'markup_snapshot' => 0,
            'addon_total_snapshot' => 0,
            'final_total_snapshot' => 100.00,
            'display_price_mode_snapshot' => 'total',
        ]);
    }
}

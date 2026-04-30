<?php

namespace Tests\Feature\Sprint1;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BookingMirrorStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_syncs_mirror_order_and_items_to_confirmed(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 120.00);

        $booking = app(BookingService::class)->create(
            [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'status' => Booking::STATUS_PENDING,
                'currency' => 'USD',
            ],
            [
                ['offer_id' => $offer->id, 'price' => 120.00],
            ],
            []
        );

        $mirrorOrder = Order::query()->findOrFail($booking->mirror_order_id);
        $this->assertSame('pending_payment', $mirrorOrder->status);

        app(BookingService::class)->confirm($booking->fresh());

        $mirrorOrder->refresh();
        $this->assertSame('confirmed', $mirrorOrder->status);
        $this->assertTrue(
            $mirrorOrder->items()->get()->every(
                fn (OrderItem $item): bool => $item->status === 'confirmed'
            )
        );
    }

    public function test_cancel_syncs_mirror_order_and_items_to_cancelled(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 75.00);

        $booking = app(BookingService::class)->create(
            [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'status' => Booking::STATUS_PENDING,
                'currency' => 'USD',
            ],
            [
                ['offer_id' => $offer->id, 'price' => 75.00],
            ],
            []
        );

        app(BookingService::class)->cancel($booking->fresh());

        $mirrorOrder = Order::query()->findOrFail($booking->mirror_order_id);
        $this->assertSame('cancelled', $mirrorOrder->status);
        $this->assertTrue(
            $mirrorOrder->items()->get()->every(
                fn (OrderItem $item): bool => $item->status === 'cancelled'
            )
        );
    }

    public function test_confirm_with_null_mirror_order_id_succeeds(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();

        $booking = Booking::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'status' => Booking::STATUS_PENDING,
            'total_price' => 0,
            'currency' => 'USD',
        ]);

        $booking->mirror_order_id = null;
        $booking->save();

        app(BookingService::class)->confirm($booking);

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
    }

    public function test_confirm_swallow_mirror_sync_failure_and_keeps_legacy_mutation(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 90.00);

        $booking = app(BookingService::class)->create(
            [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'status' => Booking::STATUS_PENDING,
                'currency' => 'USD',
            ],
            [
                ['offer_id' => $offer->id, 'price' => 90.00],
            ],
            []
        );

        $service = new class(app(OrderService::class)) extends BookingService
        {
            protected function syncMirrorOrderStatus(Booking $booking, string $orderStatus, ?string $itemStatus = null): void
            {
                throw new RuntimeException('forced mirror sync failure');
            }
        };

        $service->confirm($booking->fresh());

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Booking Mirror Sync Co '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Booking Mirror Sync User',
            'email' => 'booking-mirror-sync-'.str()->uuid().'@example.test',
            'password' => 'password',
        ]);
    }

    private function createOffer(Company $company, float $price): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Flight Offer '.str()->uuid(),
            'price' => $price,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
    }
}

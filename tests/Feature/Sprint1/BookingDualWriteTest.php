<?php

namespace Tests\Feature\Sprint1;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery\MockInterface;
use Tests\TestCase;

class BookingDualWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_dual_writes_booking_and_order_rows(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $firstOffer = $this->createOffer($company, 'flight', 110.00, 'USD');
        $secondOffer = $this->createOffer($company, 'flight', 90.00, 'USD');

        $orderCountBefore = Order::count();
        $orderItemsCountBefore = OrderItem::count();

        $booking = app(BookingService::class)->create(
            [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'status' => 'pending',
                'currency' => 'USD',
            ],
            [
                ['offer_id' => $firstOffer->id, 'price' => 110.00],
                ['offer_id' => $secondOffer->id, 'price' => 90.00],
            ],
            []
        );

        $this->assertSame(1, Booking::count());
        $this->assertSame(2, BookingItem::count());
        $this->assertSame($orderCountBefore + 1, Order::count());
        $this->assertSame($orderItemsCountBefore + 2, OrderItem::count());

        $booking->refresh();
        $order = Order::query()->firstOrFail();

        $this->assertSame('booking', $order->metadata['legacy_origin'] ?? null);
        $this->assertSame($booking->id, $order->metadata['legacy_booking_id'] ?? null);
        $this->assertSame($order->id, $booking->mirror_order_id);

        $legacyItemIds = $booking->items()->pluck('id')->sort()->values()->all();
        $orderItems = OrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('unit_price', 'desc')
            ->get();

        $this->assertCount(2, $orderItems);
        $this->assertTrue($orderItems->every(fn (OrderItem $item): bool => $item->item_type === 'flight'));
        $this->assertSame(
            ['90.00', '110.00'],
            $orderItems->pluck('unit_price')->map(fn ($v) => (string) $v)->sort()->values()->all()
        );
        $this->assertSame(
            $legacyItemIds,
            $orderItems->pluck('service_snapshot.legacy_booking_item_id')->sort()->values()->all()
        );
    }

    public function test_create_rolls_back_legacy_booking_when_mirror_order_write_fails(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 'flight', 50.00, 'USD');

        $this->mock(OrderService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('create')
                ->once()
                ->andThrow(new InvalidArgumentException('forced dual-write failure'));
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('forced dual-write failure');

        try {
            app(BookingService::class)->create(
                [
                    'user_id' => $user->id,
                    'company_id' => $company->id,
                    'status' => 'pending',
                    'currency' => 'USD',
                ],
                [
                    ['offer_id' => $offer->id, 'price' => 50.00],
                ],
                []
            );
        } finally {
            $this->assertSame(0, Booking::count());
            $this->assertSame(0, BookingItem::count());
            $this->assertSame(0, Order::count());
            $this->assertSame(0, OrderItem::count());
        }
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Booking Dual Write Co '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Booking Dual Write User',
            'email' => 'booking-dual-write-'.str()->uuid().'@example.test',
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

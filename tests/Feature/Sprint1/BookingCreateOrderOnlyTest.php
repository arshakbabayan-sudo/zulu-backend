<?php

namespace Tests\Feature\Sprint1;

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

class BookingCreateOrderOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_returns_order_and_writes_order_items_only(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $firstOffer = $this->createOffer($company, 'flight', 110.00, 'USD');
        $secondOffer = $this->createOffer($company, 'flight', 90.00, 'USD');

        $order = app(BookingService::class)->create(
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

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame('booking', $order->metadata['legacy_origin'] ?? null);
        $this->assertCount(2, $order->items);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(2, OrderItem::query()->count());
    }

    public function test_create_rolls_back_when_order_service_fails(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company, 'flight', 50.00, 'USD');

        $this->mock(OrderService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('create')
                ->once()
                ->andThrow(new InvalidArgumentException('forced order creation failure'));
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('forced order creation failure');

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
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(0, OrderItem::query()->count());
        }
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Booking Create Order Only Co '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Booking Create Order Only User',
            'email' => 'booking-create-order-only-'.str()->uuid().'@example.test',
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

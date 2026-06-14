<?php

namespace Tests\Feature;

use App\Http\Resources\Api\OrderResource;
use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageComponent;
use App\Models\User;
use App\Services\Packages\PackageOrderService;
use App\Services\UserAccount\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * My-account Phase 2 — Trips/Bookings used to show only '#order_number':
 * getTripHistory hardcoded destination/duration to null and OrderResource had
 * no package summary. These cover the real data now flowing through.
 */
class AccountTripDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_trip_history_fills_destination_and_duration_from_package(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser();
        $package = $this->makePackage($company, 'Paris', 'France', 5);

        app(PackageOrderService::class)->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);

        $row = app(UserAccountService::class)->getTripHistory($user)['items'][0];

        $this->assertSame('package', $row['type']);
        $this->assertSame('Paris, France', $row['destination']);
        $this->assertSame(5, $row['duration_days']); // no item dates → falls back to package duration
        $this->assertNotNull($row['order_number']);
        $this->assertSame('USD', $row['currency']);
    }

    public function test_trip_history_duration_prefers_travel_dates_over_package(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser();
        $package = $this->makePackage($company, 'Rome', 'Italy', 5);

        $order = app(PackageOrderService::class)->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);

        // Stamp a 3-night travel window → duration must come from the dates, not the package's 5.
        $order->items()->update(['date_from' => '2026-07-01', 'date_to' => '2026-07-04']);

        $row = app(UserAccountService::class)->getTripHistory($user)['items'][0];

        $this->assertSame('Rome, Italy', $row['destination']);
        $this->assertSame(3, $row['duration_days']);
    }

    public function test_order_resource_includes_package_summary_when_loaded(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser();
        $package = $this->makePackage($company, 'Tokyo', 'Japan', 7);

        app(PackageOrderService::class)->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);

        $order = Order::query()->with('items.package')->where('user_id', $user->id)->firstOrFail();
        $payload = (new OrderResource($order))->resolve();

        $this->assertIsArray($payload['package']);
        $this->assertSame($package->package_title, $payload['package']['package_title']);
        $this->assertSame('Tokyo', $payload['package']['destination_city']);
        $this->assertSame('Japan', $payload['package']['destination_country']);
    }

    public function test_order_resource_package_is_null_when_relation_not_loaded(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser();
        $package = $this->makePackage($company, 'Cairo', 'Egypt', 4);

        app(PackageOrderService::class)->createOrder($package, $user, [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);

        // items loaded WITHOUT their package → guard returns null (never lazy-loads = no N+1).
        $order = Order::query()->with('items')->where('user_id', $user->id)->firstOrFail();
        $payload = (new OrderResource($order))->resolve();

        $this->assertNull($payload['package']);
    }

    private function makePackage(Company $company, string $city, string $country, int $duration): Package
    {
        $packageOffer = $this->makeOffer($company, 'package', 200.00);
        $flightOffer = $this->makeOffer($company, 'flight', 120.00);

        $package = Package::query()->create([
            'offer_id' => $packageOffer->id,
            'company_id' => $company->id,
            'package_type' => 'fixed',
            'package_title' => 'Trip to '.$city,
            'destination_city' => $city,
            'destination_country' => $country,
            'duration_days' => $duration,
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

        return $package;
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Trip Details Co '.str()->uuid(),
            'type' => 'operator',
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Trip Details User',
            'email' => 'trip-details-'.str()->uuid().'@example.test',
            'password' => 'password',
        ]);
    }

    private function makeOffer(Company $company, string $type, float $price): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => $type,
            'title' => strtoupper($type).' '.str()->uuid(),
            'price' => $price,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Step 8 (duplicate-order guard) — a double-submit (double-click, impatient
 * retry, or a network retry that lost the first response) must NOT create two
 * Orders. The client sends a stable per-attempt `Idempotency-Key` header; a
 * repeat with the same (user, key) returns the existing order. No header =>
 * today's exact behaviour (a brand-new order every call). The key is scoped
 * per user, and the DB unique index on (user_id, idempotency_key) is the
 * race-safe authority.
 */
class OrderCreationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Idem Co '.str()->uuid(),
            'type' => 'operator',
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Idem Buyer',
            'email' => 'idem-'.str()->uuid().'@example.test',
            'password' => 'password',
        ]);
    }

    private function createOffer(Company $company, string $type = 'flight', float $price = 100.00): Offer
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

    private function createPackage(Company $company): Package
    {
        $packageOffer = $this->createOffer($company, 'package', 200.00);
        $flightOffer = $this->createOffer($company, 'flight', 120.00);
        $hotelOffer = $this->createOffer($company, 'hotel', 80.00);

        $package = Package::query()->create([
            'offer_id' => $packageOffer->id,
            'company_id' => $company->id,
            'package_type' => 'fixed',
            'package_title' => 'Idem Package '.str()->uuid(),
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

    // (a) marketplace — same key, same user => exactly ONE order, same id.
    public function test_marketplace_same_idempotency_key_returns_single_order(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company);
        Sanctum::actingAs($user);

        $key = (string) str()->uuid();

        $first = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/marketplace/bookings', ['offer_id' => $offer->id]);
        $second = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/marketplace/bookings', ['offer_id' => $offer->id]);

        $first->assertOk();
        $second->assertOk();

        $firstId = $first->json('data.order.id');
        $secondId = $second->json('data.order.id');

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame($key, Order::query()->findOrFail($firstId)->idempotency_key);
    }

    // (b) package-orders — same key, same user => exactly ONE order, same id.
    public function test_package_order_same_idempotency_key_returns_single_order(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $package = $this->createPackage($company);
        Sanctum::actingAs($user);

        $key = (string) str()->uuid();

        $first = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/package-orders', ['package_id' => $package->id, 'adults_count' => 1]);
        $second = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/package-orders', ['package_id' => $package->id, 'adults_count' => 1]);

        $first->assertCreated();
        $second->assertCreated();

        $firstId = $first->json('data.id');
        $secondId = $second->json('data.id');

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame($key, Order::query()->findOrFail($firstId)->idempotency_key);
    }

    // (c) no header => two calls => two orders (backward-compatible).
    public function test_marketplace_without_header_creates_two_orders(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company);
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/marketplace/bookings', ['offer_id' => $offer->id]);
        $second = $this->postJson('/api/marketplace/bookings', ['offer_id' => $offer->id]);

        $first->assertOk();
        $second->assertOk();

        $this->assertNotSame($first->json('data.order.id'), $second->json('data.order.id'));
        $this->assertSame(2, Order::query()->count());
        $this->assertNull(Order::query()->findOrFail($first->json('data.order.id'))->idempotency_key);
    }

    public function test_package_order_without_header_creates_two_orders(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $package = $this->createPackage($company);
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/package-orders', ['package_id' => $package->id, 'adults_count' => 1]);
        $second = $this->postJson('/api/package-orders', ['package_id' => $package->id, 'adults_count' => 1]);

        $first->assertCreated();
        $second->assertCreated();

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(2, Order::query()->count());
    }

    // (d) two different users, same key => two orders (key is per-user).
    public function test_same_key_different_users_creates_two_orders(): void
    {
        $company = $this->createCompany();
        $offer = $this->createOffer($company);
        $key = (string) str()->uuid();

        $userA = $this->createUser();
        Sanctum::actingAs($userA);
        $a = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/marketplace/bookings', ['offer_id' => $offer->id]);

        $userB = $this->createUser();
        Sanctum::actingAs($userB);
        $b = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/marketplace/bookings', ['offer_id' => $offer->id]);

        $a->assertOk();
        $b->assertOk();

        $this->assertNotSame($a->json('data.order.id'), $b->json('data.order.id'));
        $this->assertSame(2, Order::query()->count());
    }

    // (e) the unique index is real — a duplicate (user_id, key) insert is
    //     rejected by the database itself (the race-safe authority).
    public function test_duplicate_user_key_insert_is_prevented_by_unique_index(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $offer = $this->createOffer($company);
        Sanctum::actingAs($user);

        $key = (string) str()->uuid();
        $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/marketplace/bookings', ['offer_id' => $offer->id])
            ->assertOk();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        // A raw second insert with the SAME (user_id, idempotency_key) must be
        // rejected by the DB — proving the unique index, not just the app-level
        // pre-check, guards against duplicates (the race-safe authority).
        DB::table('orders')->insert([
            'id' => (string) str()->uuid(),
            'user_id' => $user->id,
            'idempotency_key' => $key,
            'status' => 'pending_payment',
            'currency' => 'USD',
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

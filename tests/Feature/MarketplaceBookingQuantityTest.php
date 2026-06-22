<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * C4 (frontend-audit / roadmap Step 6) — POST /api/marketplace/bookings must
 * reproduce the on-screen total (unit_price × quantity) server-side and record
 * the booking selection (meta), WITHOUT breaking the legacy { offer_id }-only
 * call. The price × quantity multiplication already exists in the pricing
 * pipeline; these tests prove quantity now reaches the server and meta persists.
 */
class MarketplaceBookingQuantityTest extends TestCase
{
    use RefreshDatabase;

    private function seedScenario(): array
    {
        $company = Company::query()->create([
            'name' => 'Marketplace Qty Co '.str()->uuid(),
            'type' => 'operator',
        ]);

        $user = User::query()->create([
            'name' => 'Marketplace Qty Buyer',
            'email' => 'marketplace-qty-'.str()->uuid().'@example.test',
            'password' => 'password',
        ]);

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Qty Flight Offer '.str()->uuid(),
            'price' => 100.00,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        Sanctum::actingAs($user);

        return [$company, $user, $offer];
    }

    public function test_booking_without_quantity_charges_unit_price_once_unchanged(): void
    {
        [, , $offer] = $this->seedScenario();

        $response = $this->postJson('/api/marketplace/bookings', [
            'offer_id' => $offer->id,
        ]);

        $response->assertOk();
        $orderId = $response->json('data.order.id');

        $order = Order::query()->with('items')->findOrFail($orderId);
        $item = $order->items->first();

        // Legacy 15% B2C fallback markup applied to a 100.00 base => 115.00.
        $this->assertSame(1, (int) $item->quantity);
        $this->assertSame('115.00', number_format((float) $item->unit_price, 2, '.', ''));
        $this->assertSame('115.00', number_format((float) $order->total, 2, '.', ''));
        // Legacy origin must remain queryable; no meta when none sent.
        $this->assertSame('booking', $order->metadata['legacy_origin'] ?? null);
        $this->assertArrayNotHasKey('meta', $order->metadata);
    }

    public function test_booking_with_quantity_multiplies_server_total(): void
    {
        [, , $offer] = $this->seedScenario();

        $response = $this->postJson('/api/marketplace/bookings', [
            'offer_id' => $offer->id,
            'quantity' => 3,
        ]);

        $response->assertOk();
        $order = Order::query()->with('items')->findOrFail($response->json('data.order.id'));
        $item = $order->items->first();

        // unit_price (115.00) × quantity (3) === 345.00, computed server-side.
        $this->assertSame(3, (int) $item->quantity);
        $this->assertSame('115.00', number_format((float) $item->unit_price, 2, '.', ''));
        $this->assertSame('345.00', number_format((float) $item->total, 2, '.', ''));
        $this->assertSame('345.00', number_format((float) $order->total, 2, '.', ''));
    }

    public function test_meta_is_persisted_without_clobbering_legacy_origin(): void
    {
        [, , $offer] = $this->seedScenario();

        $response = $this->postJson('/api/marketplace/bookings', [
            'offer_id' => $offer->id,
            'quantity' => 2,
            'meta' => [
                'pax' => ['adults' => 2, 'children' => 0],
                'dates' => ['check_in' => '2026-07-01', 'check_out' => '2026-07-05'],
                'contact' => ['name' => 'A. Buyer', 'email' => 'buyer@example.test'],
                'cabin' => 'economy',
                'notes' => 'window seat please',
            ],
        ]);

        $response->assertOk();
        $order = Order::query()->findOrFail($response->json('data.order.id'));

        $this->assertSame('booking', $order->metadata['legacy_origin'] ?? null);
        $this->assertSame(2, $order->metadata['meta']['pax']['adults'] ?? null);
        $this->assertSame('2026-07-01', $order->metadata['meta']['dates']['check_in'] ?? null);
        $this->assertSame('buyer@example.test', $order->metadata['meta']['contact']['email'] ?? null);
        $this->assertSame('window seat please', $order->metadata['meta']['notes'] ?? null);
        // Multiplier still applied alongside meta.
        $this->assertSame('230.00', number_format((float) $order->total, 2, '.', ''));
    }

    public function test_quantity_zero_is_rejected(): void
    {
        [, , $offer] = $this->seedScenario();

        $this->postJson('/api/marketplace/bookings', [
            'offer_id' => $offer->id,
            'quantity' => 0,
        ])->assertStatus(422);
    }

    public function test_quantity_above_max_is_rejected(): void
    {
        [, , $offer] = $this->seedScenario();

        $this->postJson('/api/marketplace/bookings', [
            'offer_id' => $offer->id,
            'quantity' => 100,
        ])->assertStatus(422);
    }

    public function test_negative_quantity_is_rejected(): void
    {
        [, , $offer] = $this->seedScenario();

        $this->postJson('/api/marketplace/bookings', [
            'offer_id' => $offer->id,
            'quantity' => -1,
        ])->assertStatus(422);
    }

    public function test_non_array_meta_is_rejected(): void
    {
        [, , $offer] = $this->seedScenario();

        $this->postJson('/api/marketplace/bookings', [
            'offer_id' => $offer->id,
            'meta' => 'not-an-object',
        ])->assertStatus(422);
    }

    public function test_oversized_meta_is_rejected(): void
    {
        [, , $offer] = $this->seedScenario();

        $this->postJson('/api/marketplace/bookings', [
            'offer_id' => $offer->id,
            // An unknown key bypasses the per-key length rules but trips the
            // overall 8KB meta size cap.
            'meta' => ['junk' => str_repeat('x', 9000)],
        ])->assertStatus(422);
    }
}

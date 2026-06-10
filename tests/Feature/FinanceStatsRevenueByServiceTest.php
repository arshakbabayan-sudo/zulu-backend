<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression test for the Finance summary "Revenue by service" widget 500
 * (roadmap 10.06 §2): the query joined offers ON order_items.offer_id — a
 * column that does not exist — so EVERY request errored, even on an empty
 * database. These tests execute the real SQL, so a bad column/grouping in
 * FinanceStatsController::revenueByService fails them immediately.
 */
class FinanceStatsRevenueByServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_403_for_non_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/platform-admin/finance/revenue-by-service?range=30d')->assertStatus(403);
    }

    public function test_returns_200_for_each_range(): void
    {
        Sanctum::actingAs($this->createPlatformAdmin());

        foreach (['7d', '30d', '90d', 'year'] as $range) {
            $response = $this->getJson("/api/platform-admin/finance/revenue-by-service?range={$range}");

            $response->assertOk();
            $this->assertTrue($response->json('success'));
            $this->assertIsArray($response->json('data'));
        }
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}

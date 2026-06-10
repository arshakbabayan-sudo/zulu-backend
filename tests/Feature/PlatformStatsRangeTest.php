<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap 10.06 §5 — GET /platform-admin/stats?range= (dashboard date picker).
 * Range filters the order-flow aggregates; stock totals stay all-time; a bad
 * range value silently falls back to all-time.
 */
class PlatformStatsRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_accepts_each_range_and_bad_values(): void
    {
        Sanctum::actingAs($this->createPlatformAdmin());

        foreach (['7d', '30d', '90d', 'year', 'banana', ''] as $range) {
            $response = $this->getJson('/api/platform-admin/stats?range='.$range);

            $response->assertOk();
            $this->assertTrue($response->json('success'));
            $this->assertIsInt($response->json('data.bookings_total'));
            $this->assertIsInt($response->json('data.companies_total'));
        }
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}

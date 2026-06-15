<?php

namespace Tests\Feature;

use App\Models\CommissionRule;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression test for the Finance "Commissions" stat cards 500: the query
 * referenced non-existent `rule_type` / `percent` columns on commission_rules
 * and a non-existent `status` column on commission_transactions, so EVERY
 * request errored (column-not-found) even on an empty database. These tests
 * execute the real SQL, so any bad column in FinanceStatsController::commissions
 * fails them immediately.
 */
class FinanceStatsCommissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_403_for_non_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/platform-admin/commissions/stats')->assertStatus(403);
    }

    public function test_returns_200_with_zeroed_shape_on_empty_db(): void
    {
        Sanctum::actingAs($this->createPlatformAdmin());

        $response = $this->getJson('/api/platform-admin/commissions/stats');

        $response->assertOk();
        $this->assertTrue($response->json('success'));
        $response->assertJsonStructure([
            'data' => [
                'active_policies_count',
                'recorded_amount',
                'pending_amount',
                'pending_count',
                'avg_rate_pct',
            ],
        ]);
        $this->assertSame(0, $response->json('data.active_policies_count'));
        $this->assertSame(0.0, (float) $response->json('data.avg_rate_pct'));
    }

    public function test_counts_active_percentage_rule_and_average_rate(): void
    {
        Sanctum::actingAs($this->createPlatformAdmin());

        CommissionRule::create([
            'type' => 'percentage',
            'level' => 'global',
            'percentage_value' => 12.5,
            'direction' => 'zulu_from_seller',
            'priority' => 0,
            'effective_from' => now(),
            'status' => 'active',
            'active' => true,
        ]);
        // An inactive rule must NOT be counted.
        CommissionRule::create([
            'type' => 'percentage',
            'level' => 'global',
            'percentage_value' => 50.0,
            'direction' => 'zulu_from_seller',
            'priority' => 0,
            'effective_from' => now(),
            'status' => 'inactive',
            'active' => false,
        ]);

        $response = $this->getJson('/api/platform-admin/commissions/stats');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.active_policies_count'));
        $this->assertSame(12.5, (float) $response->json('data.avg_rate_pct'));
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}

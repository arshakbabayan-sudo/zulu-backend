<?php

namespace Tests\Feature\Statistics;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPlatformStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_platform_admin(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/platform-admin/statistics/dashboard')->assertStatus(403);
    }

    public function test_dashboard_returns_aggregated_snapshot(): void
    {
        $admin = $this->createPlatformAdmin();

        // Seed some orders
        $company = Company::query()->create(['name' => 'Stat Co', 'type' => 'operator']);
        Order::query()->create([
            'order_number' => 'ORD-S-1',
            'company_id' => $company->id,
            'buyer_type' => 'client',
            'status' => 'paid',
            'currency' => 'USD',
            'subtotal' => 500,
            'tax' => 0,
            'total' => 500,
        ]);
        Order::query()->create([
            'order_number' => 'ORD-S-2',
            'company_id' => $company->id,
            'buyer_type' => 'client',
            'status' => 'cart',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/statistics/dashboard?days=30');

        $response->assertOk();
        $response->assertJsonStructure(['data' => [
            'window_days', 'orders', 'revenue', 'users', 'sellers',
            'vouchers', 'contracts', 'connections', 'package_sagas',
            'insurance', 'loyalty', 'top_sellers',
        ]]);

        $this->assertSame(30, $response->json('data.window_days'));
        $this->assertSame(500.0, (float) $response->json('data.revenue.total'));
        $this->assertSame(1, $response->json('data.revenue.order_count'));
        $this->assertSame(1, $response->json('data.orders.open_carts'));
        $this->assertSame(1, $response->json('data.orders.paid'));
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}

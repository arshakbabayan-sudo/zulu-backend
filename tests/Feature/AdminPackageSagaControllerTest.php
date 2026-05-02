<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PackageBookingSaga;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPackageSagaControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_platform_admin(): void
    {
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);
        $this->getJson('/api/platform-admin/package-sagas')->assertStatus(403);
    }

    public function test_index_returns_list(): void
    {
        $admin = $this->createPlatformAdmin();
        $this->seedSagas(3);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/package-sagas');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(3, count($response->json('data')));
    }

    public function test_index_filters_by_status(): void
    {
        $admin = $this->createPlatformAdmin();
        $this->makeSagaWithStatus('confirmed');
        $this->makeSagaWithStatus('failed');

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/package-sagas?status=failed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('failed', $response->json('data.0.status'));
    }

    public function test_show_returns_saga_with_relations(): void
    {
        $admin = $this->createPlatformAdmin();
        $saga = $this->makeSagaWithStatus('confirmed');

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/package-sagas/'.$saga->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $saga->id);
        $response->assertJsonStructure(['data' => ['id', 'status', 'order', 'components', 'logs']]);
    }

    public function test_show_returns_404_for_unknown(): void
    {
        $admin = $this->createPlatformAdmin();
        Sanctum::actingAs($admin);
        $this->getJson('/api/platform-admin/package-sagas/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    public function test_retry_returns_existing_terminal_saga(): void
    {
        $admin = $this->createPlatformAdmin();
        $saga = $this->makeSagaWithStatus('confirmed');

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/package-sagas/'.$saga->id.'/retry');

        $response->assertOk();
        $response->assertJsonPath('data.id', $saga->id);
        $response->assertJsonPath('data.status', 'confirmed');
    }

    public function test_stats_returns_grouped_counts(): void
    {
        $admin = $this->createPlatformAdmin();
        $this->makeSagaWithStatus('confirmed');
        $this->makeSagaWithStatus('failed');

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/package-sagas/stats');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(2, $response->json('data.total'));
        $this->assertIsArray($response->json('data.by_status'));
    }

    private function makeSagaWithStatus(string $status): PackageBookingSaga
    {
        $order = Order::query()->create([
            'order_number' => 'ORD-SAGA-A-'.str()->random(6),
            'buyer_type' => 'client',
            'status' => 'paid',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
        ]);

        return PackageBookingSaga::query()->create([
            'order_id' => $order->id,
            'status' => $status,
            'started_at' => now(),
        ]);
    }

    private function seedSagas(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->makeSagaWithStatus('pending');
        }
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}

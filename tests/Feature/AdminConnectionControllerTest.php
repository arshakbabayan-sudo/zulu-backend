<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Partnerships\PartnerConnectionService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminConnectionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_platform_admin(): void
    {
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);
        $this->getJson('/api/platform-admin/connections')->assertStatus(403);
    }

    public function test_index_returns_paginated_list(): void
    {
        $admin = $this->createPlatformAdmin();
        $this->seedConnections(3);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/connections');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(3, count($response->json('data')));
    }

    public function test_index_filters_by_status(): void
    {
        $admin = $this->createPlatformAdmin();
        $service = app(PartnerConnectionService::class);

        // 1 proposed
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        $service->propose($a, $b, $admin);

        // 1 active
        $c = $this->makeCompany();
        $d = $this->makeCompany();
        $accepter = User::factory()->create();
        $conn = $service->propose($c, $d, $admin);
        $service->accept($conn, $accepter);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/connections?status=active');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('active', $response->json('data.0.status'));
    }

    public function test_show_returns_connection_with_relations(): void
    {
        $admin = $this->createPlatformAdmin();
        $service = app(PartnerConnectionService::class);
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        $conn = $service->propose($a, $b, $admin);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/connections/'.$conn->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $conn->id);
        $response->assertJsonPath('data.seller_a.id', $a->id);
        $response->assertJsonPath('data.seller_b.id', $b->id);
    }

    public function test_show_returns_404_for_unknown(): void
    {
        $admin = $this->createPlatformAdmin();
        Sanctum::actingAs($admin);
        $this->getJson('/api/platform-admin/connections/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    public function test_force_terminate_with_reason(): void
    {
        $admin = $this->createPlatformAdmin();
        $service = app(PartnerConnectionService::class);
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        $conn = $service->propose($a, $b, $admin);
        $accepter = User::factory()->create();
        $service->accept($conn, $accepter);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/connections/'.$conn->id.'/force-terminate', [
            'reason' => 'Compliance violation',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'terminated');
        $this->assertStringStartsWith('ADMIN: ', $response->json('data.termination_reason'));
    }

    public function test_force_terminate_requires_reason(): void
    {
        $admin = $this->createPlatformAdmin();
        $service = app(PartnerConnectionService::class);
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        $conn = $service->propose($a, $b, $admin);
        $service->accept($conn, User::factory()->create());

        Sanctum::actingAs($admin);
        $this->postJson('/api/platform-admin/connections/'.$conn->id.'/force-terminate', [])
            ->assertStatus(422);
    }

    public function test_stats_returns_grouped_counts(): void
    {
        $admin = $this->createPlatformAdmin();
        $this->seedConnections(2);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/connections/stats');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(2, $response->json('data.total'));
        $this->assertIsArray($response->json('data.by_status'));
        $this->assertIsArray($response->json('data.by_type'));
    }

    private function seedConnections(int $count): void
    {
        $service = app(PartnerConnectionService::class);
        for ($i = 0; $i < $count; $i++) {
            $a = $this->makeCompany();
            $b = $this->makeCompany();
            $service->propose($a, $b, User::factory()->create());
        }
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Admin Conn Test '.str()->random(6),
            'type' => 'operator',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap 10.06 §5 — GET /platform-admin/search ("Search anything").
 */
class PlatformAdminGlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_platform_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/platform-admin/search?q=zulu')->assertStatus(403);
    }

    public function test_short_query_returns_empty_groups(): void
    {
        Sanctum::actingAs($this->createPlatformAdmin());

        $response = $this->getJson('/api/platform-admin/search?q=a');

        $response->assertOk();
        $this->assertSame([], $response->json('data.companies'));
        $this->assertSame([], $response->json('data.users'));
        $this->assertSame([], $response->json('data.bookings'));
    }

    public function test_finds_companies_and_users_grouped(): void
    {
        $admin = $this->createPlatformAdmin();
        Company::query()->create(['name' => 'Searchable Travel LLC', 'slug' => 'searchable-travel', 'type' => 'operator']);
        User::factory()->create(['name' => 'Searchable Customer', 'email' => 'searchable@example.com']);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/search?q=searchable');

        $response->assertOk();
        $companies = $response->json('data.companies');
        $users = $response->json('data.users');
        $this->assertCount(1, $companies);
        $this->assertSame('Searchable Travel LLC', $companies[0]['label']);
        $this->assertStringStartsWith('/platform/companies/', $companies[0]['href']);
        $this->assertNotEmpty($users);
        $this->assertStringContainsString('searchable@example.com', $users[0]['label']);
        $this->assertIsArray($response->json('data.bookings'));
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}

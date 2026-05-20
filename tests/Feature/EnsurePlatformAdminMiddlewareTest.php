<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * I1 audit F-4 regression: every route under /api/platform-admin/*
 * must reject non-platform-admin callers at the group middleware
 * BEFORE any controller method runs.
 */
class EnsurePlatformAdminMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_gets_401_on_platform_admin_route(): void
    {
        $this->getJson('/api/platform-admin/stats')->assertStatus(401);
    }

    public function test_regular_user_without_platform_admin_role_gets_403(): void
    {
        $user = User::query()->create([
            'name' => 'Regular User',
            'email' => 'reg-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        // Intentionally NOT a platform admin
        Sanctum::actingAs($user);

        $this->getJson('/api/platform-admin/stats')->assertStatus(403);
        $this->getJson('/api/platform-admin/companies')->assertStatus(403);
        $this->getJson('/api/platform-admin/applications')->assertStatus(403);
    }

    public function test_platform_admin_role_passes_middleware(): void
    {
        $role = Role::query()->firstOrCreate(['name' => 'platform_admin']);
        $company = Company::query()->create([
            'name' => 'Test Co '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
        $user = User::query()->create([
            'name' => 'Middleware Test',
            'email' => 'mw-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        Sanctum::actingAs($user);

        // Should pass the middleware (may still hit downstream issues like
        // 404 if no data, but NOT 403)
        $res = $this->getJson('/api/platform-admin/stats');
        $this->assertNotSame(403, $res->status(), 'Platform admin must not be blocked by middleware');
    }
}

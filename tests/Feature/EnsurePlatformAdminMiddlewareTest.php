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

    /**
     * Phase 6 — an operator (company_admin, no platform.* perm) may reach the
     * allowlisted tenant-scoped READ endpoints, but NOT writes, NOT super-only
     * data, NOT off-list routes.
     */
    public function test_operator_reaches_allowlisted_reads_only(): void
    {
        $role = Role::query()->firstOrCreate(['name' => 'company_admin']);
        $company = Company::query()->create(['name' => 'Op Co '.str()->uuid(), 'type' => 'operator']);
        $operator = User::query()->create([
            'name' => 'Op', 'email' => 'op-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'), 'status' => User::STATUS_ACTIVE,
        ]);
        $operator->companies()->attach($company->id, ['role_id' => $role->id]);

        Sanctum::actingAs($operator);

        // Allowlisted scoped reads → NOT 403 (operator sees own data).
        $this->assertNotSame(403, $this->getJson('/api/platform-admin/bookings')->status());
        $this->assertNotSame(403, $this->getJson('/api/platform-admin/companies')->status());
        $this->assertNotSame(403, $this->getJson('/api/platform-admin/bookings/stats')->status());

        // Off-list super-only reads → 403.
        $this->getJson('/api/platform-admin/customers')->assertStatus(403);
        $this->getJson('/api/platform-admin/commission-limits')->assertStatus(403);
        $this->getJson('/api/platform-admin/loyalty/accounts')->assertStatus(403);

        // Dashboard stats are now allowlisted (Phase 5) — operator reaches
        // them scoped to their own company, NOT 403.
        $this->assertNotSame(403, $this->getJson('/api/platform-admin/stats')->status());

        // Writes → 403 (deny-by-default; allowlist is GET only).
        $this->patchJson('/api/platform-admin/companies/1/toggle-seller', [])->assertStatus(403);
    }

    public function test_b2c_customer_is_forbidden_everywhere(): void
    {
        // No role-bound membership at all = B2C customer.
        $customer = User::query()->create([
            'name' => 'B2C', 'email' => 'b2c-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'), 'status' => User::STATUS_ACTIVE,
        ]);
        Sanctum::actingAs($customer);

        $this->getJson('/api/platform-admin/bookings')->assertStatus(403);
        $this->getJson('/api/platform-admin/companies')->assertStatus(403);
    }
}

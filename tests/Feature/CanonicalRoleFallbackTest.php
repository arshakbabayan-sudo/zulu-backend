<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Canonical-role fallback (AdminAccessService::canonicalRoleForUser).
 *
 * A user with NO role-bound membership (plain B2C customer / unverified
 * signup) must surface as `customer` in the /api/account/me payload
 * (`canonical_role` + `context.world`) — previously the method fell through
 * to `operator_admin`, so the admin header mislabeled B2C customers as
 * operators. Users WITH memberships keep the existing labels: operator-tier
 * roles → operator_admin, pure agent → agent.
 */
class CanonicalRoleFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create(['name' => 'Co '.uniqid(), 'type' => 'agency']);
    }

    private function memberWithRole(string $roleName): User
    {
        // Migrations seed the RBAC catalog, so reuse existing rows where present.
        $role = Role::updateOrCreate(['name' => $roleName], ['scope' => Role::SCOPE_COMPANY]);

        $user = User::factory()->create();
        UserCompany::create([
            'user_id' => $user->id,
            'company_id' => $this->company()->id,
            'role_id' => $role->id,
        ]);

        return $user->fresh();
    }

    public function test_membership_less_user_gets_customer_canonical_role(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);
        $res = $this->getJson('/api/account/me');

        $res->assertStatus(200);
        $this->assertSame('customer', $res->json('data.canonical_role'));
        $this->assertSame('customer', $res->json('data.context.world'));
        $this->assertSame('customer', $res->json('data.context.canonical_role'));
        $this->assertSame([], $res->json('data.roles'));
    }

    public function test_company_admin_member_keeps_operator_admin_canonical_role(): void
    {
        $user = $this->memberWithRole('company_admin');

        Sanctum::actingAs($user);
        $res = $this->getJson('/api/account/me');

        $res->assertStatus(200);
        $this->assertSame('operator_admin', $res->json('data.canonical_role'));
        $this->assertSame('operator_admin', $res->json('data.context.world'));
    }

    public function test_pure_agent_member_keeps_agent_canonical_role(): void
    {
        $user = $this->memberWithRole('agent');

        Sanctum::actingAs($user);
        $res = $this->getJson('/api/account/me');

        $res->assertStatus(200);
        $this->assertSame('agent', $res->json('data.canonical_role'));
        $this->assertSame('agent', $res->json('data.context.world'));
    }
}

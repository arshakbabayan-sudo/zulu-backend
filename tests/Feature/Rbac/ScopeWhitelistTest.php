<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 1 / Step B.2 — RBAC scope whitelist enforcement.
 *
 * Verifies that:
 *  - A super-admin caller CAN grant a platform-scoped role.
 *  - A non-super-admin (company_admin) CANNOT grant a platform-scoped
 *    role even though they could otherwise manage the target company.
 *  - The same caller CAN still grant company-scoped roles.
 *
 * Backstop for the audit §7 hole: previously, anyone who could reach
 * the role-assignment endpoint could grant ANY role name in the
 * COMPANY_ROLE_NAMES allow-list, including operator_admin which is
 * now classified as platform-scoped (per Phase 1 spec).
 */
class ScopeWhitelistTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): array
    {
        $roles = [
            'super_admin' => Role::SCOPE_PLATFORM,
            'platform_admin' => Role::SCOPE_PLATFORM,
            'operator_admin' => Role::SCOPE_PLATFORM,
            'company_admin' => Role::SCOPE_COMPANY,
            'company_operator' => Role::SCOPE_COMPANY,
            'company_viewer' => Role::SCOPE_COMPANY,
        ];
        $ids = [];
        foreach ($roles as $name => $scope) {
            // upsert — migration 2026_06_10_000300 already seeds the company
            // team roles, so a blind insert hits the unique(name) constraint.
            $ids[$name] = (int) Role::updateOrCreate(['name' => $name], ['scope' => $scope])->id;
        }

        return $ids;
    }

    private function makeCompany(): int
    {
        return DB::table('companies')->insertGetId([
            'name' => 'TestCo '.uniqid(),
            'type' => 'operator',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_super_admin_can_grant_platform_scoped_role(): void
    {
        $roleIds = $this->seedRoles();
        $companyId = $this->makeCompany();

        $superAdmin = User::factory()->create();
        UserCompany::create([
            'user_id' => $superAdmin->id,
            'company_id' => $companyId,
            'role_id' => $roleIds['super_admin'],
        ]);

        Sanctum::actingAs($superAdmin);

        $response = $this->postJson(
            "/api/companies/{$companyId}/users",
            [
                'name' => 'New User',
                'email' => 'newuser-'.uniqid().'@example.com',
                'password' => 'password123',
                'role_name' => 'operator_admin', // platform-scoped
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
    }

    public function test_company_admin_cannot_grant_platform_scoped_role(): void
    {
        $roleIds = $this->seedRoles();
        $companyId = $this->makeCompany();

        $companyAdmin = User::factory()->create();
        UserCompany::create([
            'user_id' => $companyAdmin->id,
            'company_id' => $companyId,
            'role_id' => $roleIds['company_admin'],
        ]);

        Sanctum::actingAs($companyAdmin);

        $response = $this->postJson(
            "/api/companies/{$companyId}/users",
            [
                'name' => 'Attempted Promotion',
                'email' => 'promo-'.uniqid().'@example.com',
                'password' => 'password123',
                'role_name' => 'operator_admin', // platform-scoped — should reject
            ]
        );

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonStructure(['message', 'errors' => ['role_name']]);
    }

    public function test_company_admin_can_grant_company_scoped_role(): void
    {
        $roleIds = $this->seedRoles();
        $companyId = $this->makeCompany();

        $companyAdmin = User::factory()->create();
        UserCompany::create([
            'user_id' => $companyAdmin->id,
            'company_id' => $companyId,
            'role_id' => $roleIds['company_admin'],
        ]);

        Sanctum::actingAs($companyAdmin);

        $response = $this->postJson(
            "/api/companies/{$companyId}/users",
            [
                'name' => 'Regular Member',
                'email' => 'member-'.uniqid().'@example.com',
                'password' => 'password123',
                'role_name' => 'company_operator', // company-scoped
            ]
        );

        // company_operator (rank 2) vs company_admin caller (rank 3) — granter
        // has higher rank, scope is company → both checks pass.
        $response->assertStatus(201);
    }

    public function test_update_role_endpoint_also_enforces_scope_whitelist(): void
    {
        $roleIds = $this->seedRoles();
        $companyId = $this->makeCompany();

        $companyAdmin = User::factory()->create();
        UserCompany::create([
            'user_id' => $companyAdmin->id,
            'company_id' => $companyId,
            'role_id' => $roleIds['company_admin'],
        ]);

        $targetUser = User::factory()->create();
        UserCompany::create([
            'user_id' => $targetUser->id,
            'company_id' => $companyId,
            'role_id' => $roleIds['company_operator'],
        ]);

        Sanctum::actingAs($companyAdmin);

        $response = $this->patchJson(
            "/api/companies/{$companyId}/users/{$targetUser->id}/role",
            [
                'role_name' => 'operator_admin', // attempted promotion to platform-scoped
            ]
        );

        $response->assertStatus(403);
    }
}

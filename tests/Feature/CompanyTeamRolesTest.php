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
 * Roadmap 10.06 §7 — company team role catalog (Manager + the modal roles).
 *
 * The Add-employee modal offered company_operator / company_viewer but the
 * roles were never seeded (prod 422'd "Role not found"); §7 also adds the
 * `company_manager` ("Manager") rank-3 role. Migration 2026_06_10_000300
 * seeds all three with scope=company.
 */
class CompanyTeamRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::updateOrCreate(['name' => 'company_admin'], ['scope' => Role::SCOPE_COMPANY]);
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Team Roles Co '.uniqid(), 'type' => 'operator']);
    }

    private function attach(User $user, Company $company, string $roleName): void
    {
        UserCompany::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => (int) Role::query()->where('name', $roleName)->value('id'),
        ]);
    }

    public function test_migration_seeds_the_team_roles_as_company_scoped(): void
    {
        foreach (['company_manager', 'company_operator', 'company_viewer'] as $name) {
            $role = Role::query()->where('name', $name)->first();
            $this->assertNotNull($role, "role {$name} missing");
            $this->assertSame(Role::SCOPE_COMPANY, $role->scope, "role {$name} scope");
        }
    }

    public function test_owner_can_add_employee_with_manager_role(): void
    {
        $co = $this->company();
        $owner = User::factory()->create(['status' => 'active']);
        $this->attach($owner, $co, 'company_admin');

        Sanctum::actingAs($owner->fresh());

        $email = 'manager-'.uniqid().'@test.local';
        $this->postJson("/api/companies/{$co->id}/users", [
            'mode' => 'direct',
            'name' => 'New Manager',
            'email' => $email,
            'role_name' => 'company_manager',
            'password' => 'secret-123',
        ])->assertSuccessful();

        $created = User::query()->where('email', $email)->firstOrFail();
        $this->assertTrue(
            UserCompany::query()
                ->where('user_id', $created->id)
                ->where('company_id', $co->id)
                ->where('role_id', Role::query()->where('name', 'company_manager')->value('id'))
                ->exists()
        );
    }

    public function test_manager_can_manage_staff_but_not_the_owner(): void
    {
        $co = $this->company();
        $owner = User::factory()->create(['status' => 'active']);
        $this->attach($owner, $co, 'company_admin');
        $manager = User::factory()->create(['status' => 'active']);
        $this->attach($manager, $co, 'company_manager');
        $staff = User::factory()->create(['status' => 'active']);
        $this->attach($staff, $co, 'company_operator');

        Sanctum::actingAs($manager->fresh());

        // Manager (rank 3) acts on rank-2 staff — allowed.
        $this->patchJson("/api/companies/{$co->id}/users/{$staff->id}/deactivate")->assertOk();
        $this->assertSame('inactive', $staff->fresh()->status);
        $this->patchJson("/api/companies/{$co->id}/users/{$staff->id}/reactivate")->assertOk();

        // ...but not on the rank-4 owner.
        $this->patchJson("/api/companies/{$co->id}/users/{$owner->id}/deactivate")->assertForbidden();
        $this->assertSame('active', $owner->fresh()->status);
    }

    public function test_manager_cannot_grant_owner_role(): void
    {
        $co = $this->company();
        $manager = User::factory()->create(['status' => 'active']);
        $this->attach($manager, $co, 'company_manager');

        Sanctum::actingAs($manager->fresh());

        $this->postJson("/api/companies/{$co->id}/users", [
            'mode' => 'direct',
            'name' => 'Sneaky Owner',
            'email' => 'sneaky-'.uniqid().'@test.local',
            'role_name' => 'company_admin',
            'password' => 'secret-123',
        ])->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the super-admin-only role changer:
 *   PATCH /api/platform-admin/users/{id}/role
 *
 * Contract:
 *   REQUEST  { role_name: string|null, company_id?: int|null, intended_role?: 'operator'|'agent'|null }
 *   SUCCESS  { success: true, message, data: { user_id, role_name, company_id, canonical_role } }
 *   ERRORS   403 (not super), 422 (self / last-super / company required / unknown role)
 *
 * Roles live ONLY in the user_company.role_id pivot (no users.role column).
 * SQLite CI enforces FKs, so every Company/Role/User parent row is real.
 */
class PlatformAdminChangeRoleTest extends TestCase
{
    use RefreshDatabase;

    // ── (a) membership-less B2C user + role_name 'agent' + company_id → 200 ──
    public function test_attaches_role_to_membership_less_user(): void
    {
        $super = $this->makeSuperAdmin();
        $company = $this->makeCompany();
        $agentRole = $this->makeRole('agent', Role::SCOPE_COMPANY);

        $b2c = $this->makeUser('b2c@example.test');

        Sanctum::actingAs($super);

        $response = $this->patchJson("/api/platform-admin/users/{$b2c->id}/role", [
            'role_name' => 'agent',
            'company_id' => $company->id,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'user_id' => $b2c->id,
                'role_name' => 'agent',
                'company_id' => $company->id,
                'canonical_role' => 'agent',
            ],
        ]);

        $this->assertDatabaseHas('user_company', [
            'user_id' => $b2c->id,
            'company_id' => $company->id,
            'role_id' => $agentRole->id,
        ]);
    }

    // ── (b) existing company_admin membership + role_name 'agent' → updated ──
    public function test_updates_existing_membership_role(): void
    {
        $super = $this->makeSuperAdmin();
        $company = $this->makeCompany();
        $adminRole = $this->makeRole('company_admin', Role::SCOPE_COMPANY);
        $agentRole = $this->makeRole('agent', Role::SCOPE_COMPANY);

        $staff = $this->makeUser('staff@example.test');
        UserCompany::create([
            'user_id' => $staff->id,
            'company_id' => $company->id,
            'role_id' => $adminRole->id,
        ]);

        Sanctum::actingAs($super);

        $response = $this->patchJson("/api/platform-admin/users/{$staff->id}/role", [
            'role_name' => 'agent',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'user_id' => $staff->id,
                'role_name' => 'agent',
                'company_id' => $company->id,
                'canonical_role' => 'agent',
            ],
        ]);

        // The single membership row was updated in place (not duplicated).
        $this->assertDatabaseHas('user_company', [
            'user_id' => $staff->id,
            'company_id' => $company->id,
            'role_id' => $agentRole->id,
        ]);
        $this->assertDatabaseMissing('user_company', [
            'user_id' => $staff->id,
            'role_id' => $adminRole->id,
        ]);
        $this->assertSame(
            1,
            UserCompany::query()->where('user_id', $staff->id)->count()
        );
    }

    // ── (c) role_name null → membership detached, canonical 'customer' ──
    public function test_detaches_membership_when_role_name_null(): void
    {
        $super = $this->makeSuperAdmin();
        $company = $this->makeCompany();
        $adminRole = $this->makeRole('company_admin', Role::SCOPE_COMPANY);

        $staff = $this->makeUser('detach@example.test');
        UserCompany::create([
            'user_id' => $staff->id,
            'company_id' => $company->id,
            'role_id' => $adminRole->id,
        ]);

        Sanctum::actingAs($super);

        $response = $this->patchJson("/api/platform-admin/users/{$staff->id}/role", [
            'role_name' => null,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'user_id' => $staff->id,
                'role_name' => null,
                'company_id' => $company->id,
                'canonical_role' => 'customer',
            ],
        ]);

        $this->assertDatabaseMissing('user_company', [
            'user_id' => $staff->id,
        ]);
    }

    // ── (d) company-scoped role without company_id on membership-less → 422 ──
    public function test_company_scoped_role_without_company_id_is_422(): void
    {
        $super = $this->makeSuperAdmin();
        $this->makeRole('agent', Role::SCOPE_COMPANY);
        $b2c = $this->makeUser('no-company@example.test');

        Sanctum::actingAs($super);

        $response = $this->patchJson("/api/platform-admin/users/{$b2c->id}/role", [
            'role_name' => 'agent',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);

        $this->assertDatabaseMissing('user_company', [
            'user_id' => $b2c->id,
        ]);
    }

    // ── (e) self role-change → 422 ──
    public function test_self_role_change_is_422(): void
    {
        $super = $this->makeSuperAdmin();
        $this->makeRole('agent', Role::SCOPE_COMPANY);

        Sanctum::actingAs($super);

        $response = $this->patchJson("/api/platform-admin/users/{$super->id}/role", [
            'role_name' => 'agent',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    // ── (f) demoting the last super_admin → 422 and membership untouched ──
    //
    // With only ONE super-admin in the DB, the sole super is also the only
    // caller who could act. Both the self-change guard and the last-super guard
    // return 422 and leave the membership intact — this asserts that safety net
    // holds for the sole super.
    public function test_last_super_admin_demotion_is_422_and_keeps_membership(): void
    {
        $superRole = $this->makeRole('super_admin', Role::SCOPE_PLATFORM);
        $this->makeRole('agent', Role::SCOPE_COMPANY);
        $platform = $this->makeCompany('Platform Co', 'platform');

        // Exactly ONE super-admin in the whole DB.
        $lastSuper = $this->makeUser('last-super@example.test');
        $membership = UserCompany::create([
            'user_id' => $lastSuper->id,
            'company_id' => $platform->id,
            'role_id' => $superRole->id,
        ]);

        $this->assertSame(1, User::query()->superAdmins()->count());

        Sanctum::actingAs($lastSuper);

        $response = $this->patchJson("/api/platform-admin/users/{$lastSuper->id}/role", [
            'role_name' => 'agent',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);

        // Membership untouched — still super_admin on the platform company.
        $this->assertDatabaseHas('user_company', [
            'id' => $membership->id,
            'user_id' => $lastSuper->id,
            'role_id' => $superRole->id,
        ]);
    }

    // Guard must NOT over-fire: with TWO supers, demoting one distinct super is
    // allowed (one remains). Proves the last-super check counts correctly.
    public function test_demoting_one_of_two_super_admins_is_allowed(): void
    {
        $superRole = $this->makeRole('super_admin', Role::SCOPE_PLATFORM);
        $agentRole = $this->makeRole('agent', Role::SCOPE_COMPANY);
        $platform = $this->makeCompany('Platform Co', 'platform');

        $actor = $this->makeUser('actor-super@example.test');
        UserCompany::create([
            'user_id' => $actor->id,
            'company_id' => $platform->id,
            'role_id' => $superRole->id,
        ]);

        $target = $this->makeUser('target-super@example.test');
        UserCompany::create([
            'user_id' => $target->id,
            'company_id' => $platform->id,
            'role_id' => $superRole->id,
        ]);

        $this->assertSame(2, User::query()->superAdmins()->count());

        Sanctum::actingAs($actor);

        // Re-point the target's platform membership to a company-scoped agent
        // role (companyId matches the existing membership → updated in place).
        $response = $this->patchJson("/api/platform-admin/users/{$target->id}/role", [
            'role_name' => 'agent',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('user_company', [
            'user_id' => $target->id,
            'role_id' => $agentRole->id,
        ]);
        // One super remains.
        $this->assertSame(1, User::query()->superAdmins()->count());
    }

    // ── (g) non-super-admin caller → 403 ──
    public function test_non_super_admin_caller_is_403(): void
    {
        $company = $this->makeCompany();
        $adminRole = $this->makeRole('company_admin', Role::SCOPE_COMPANY);

        // A company_admin (admin-panel accessible, but NOT super).
        $companyAdmin = $this->makeUser('co-admin@example.test');
        UserCompany::create([
            'user_id' => $companyAdmin->id,
            'company_id' => $company->id,
            'role_id' => $adminRole->id,
        ]);

        $target = $this->makeUser('target@example.test');
        $this->makeRole('agent', Role::SCOPE_COMPANY);

        Sanctum::actingAs($companyAdmin);

        $response = $this->patchJson("/api/platform-admin/users/{$target->id}/role", [
            'role_name' => 'agent',
            'company_id' => $company->id,
        ]);

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
    }

    // ── (h) intended_role 'operator' persists on users.intended_role ──
    public function test_intended_role_persists(): void
    {
        $super = $this->makeSuperAdmin();
        $company = $this->makeCompany();
        $this->makeRole('agent', Role::SCOPE_COMPANY);

        $b2c = $this->makeUser('intended@example.test');

        Sanctum::actingAs($super);

        $response = $this->patchJson("/api/platform-admin/users/{$b2c->id}/role", [
            'role_name' => 'agent',
            'company_id' => $company->id,
            'intended_role' => 'operator',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $b2c->id,
            'intended_role' => 'operator',
        ]);
    }

    // ── unknown role name → 422 ──
    public function test_unknown_role_name_is_422(): void
    {
        $super = $this->makeSuperAdmin();
        $company = $this->makeCompany();
        $b2c = $this->makeUser('unknown-role@example.test');

        Sanctum::actingAs($super);

        $response = $this->patchJson("/api/platform-admin/users/{$b2c->id}/role", [
            'role_name' => 'does_not_exist',
            'company_id' => $company->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    // ─── helpers ────────────────────────────────────────────────────────

    private function makeUser(?string $email = null): User
    {
        return User::query()->create([
            'name' => 'Role Test',
            'email' => $email ?? 'role-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function makeCompany(?string $name = null, string $type = 'operator'): Company
    {
        return Company::query()->create([
            'name' => $name ?? 'Role Co '.str()->uuid(),
            'type' => $type,
        ]);
    }

    private function makeRole(string $name, string $scope): Role
    {
        return Role::query()->firstOrCreate(['name' => $name], ['scope' => $scope]);
    }

    private function makeSuperAdmin(): User
    {
        $user = $this->makeUser('super-'.str()->uuid().'@example.test');
        $role = $this->makeRole('super_admin', Role::SCOPE_PLATFORM);
        $platform = $this->makeCompany('Platform '.str()->uuid(), 'platform');
        UserCompany::create([
            'user_id' => $user->id,
            'company_id' => $platform->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }
}

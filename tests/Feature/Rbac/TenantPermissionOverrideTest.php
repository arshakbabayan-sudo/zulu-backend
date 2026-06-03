<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase Գ.6 / Bucket D.4 — per-employee permission overrides.
 *
 * Covers the effective-permission resolver, the tenant CompanyRbacController
 * GET/PUT endpoints, and the ceiling / rank / tenant-isolation guards.
 */
class TenantPermissionOverrideTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Role> */
    private array $roles = [];

    /** @var array<string, Permission> */
    private array $perms = [];

    private function seedRbac(): void
    {
        // Migrations seed the RBAC catalog, so reuse existing rows where present.
        $this->roles = [
            'super_admin' => Role::updateOrCreate(['name' => 'super_admin'], ['scope' => Role::SCOPE_PLATFORM]),
            'company_admin' => Role::updateOrCreate(['name' => 'company_admin'], ['scope' => Role::SCOPE_COMPANY]),
            'company_operator' => Role::updateOrCreate(['name' => 'company_operator'], ['scope' => Role::SCOPE_COMPANY]),
            'agent' => Role::updateOrCreate(['name' => 'agent'], ['scope' => Role::SCOPE_COMPANY]),
        ];

        foreach (['bookings.view', 'bookings.confirm', 'offers.create', 'cars.delete', 'super_admin', 'platform.settings.manage'] as $name) {
            $this->perms[$name] = Permission::firstOrCreate(['name' => $name]);
        }

        $this->roles['company_admin']->permissions()->sync($this->idsOf([
            'bookings.view', 'bookings.confirm', 'offers.create', 'cars.delete',
        ]));
        $this->roles['company_operator']->permissions()->sync($this->idsOf([
            'bookings.view', 'bookings.confirm',
        ]));
        $this->roles['agent']->permissions()->sync($this->idsOf(['bookings.view']));
    }

    /** @param list<string> $names @return list<int> */
    private function idsOf(array $names): array
    {
        return array_map(fn (string $n) => $this->perms[$n]->id, $names);
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Co '.uniqid(), 'type' => 'agency']);
    }

    private function member(string $roleKey, Company $company): User
    {
        $user = User::factory()->create();
        UserCompany::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $this->roles[$roleKey]->id,
        ]);

        return $user;
    }

    // ─── resolver (model) ───────────────────────────────────────────────

    public function test_no_override_keeps_pure_role_behaviour(): void
    {
        $this->seedRbac();
        $co = $this->company();
        $agent = $this->member('agent', $co);

        $this->assertTrue($agent->hasCompanyPermission((int) $co->id, 'bookings.view'));
        $this->assertFalse($agent->hasCompanyPermission((int) $co->id, 'offers.create'));
        $this->assertSame(['bookings.view'], $agent->effectivePermissionsForCompany((int) $co->id));
    }

    public function test_allow_override_adds_and_deny_override_removes(): void
    {
        $this->seedRbac();
        $co = $this->company();
        $agent = $this->member('agent', $co);

        UserPermissionOverride::create([
            'user_id' => $agent->id, 'company_id' => $co->id,
            'permission_id' => $this->perms['offers.create']->id, 'effect' => 'allow',
        ]);
        UserPermissionOverride::create([
            'user_id' => $agent->id, 'company_id' => $co->id,
            'permission_id' => $this->perms['bookings.view']->id, 'effect' => 'deny',
        ]);

        $this->assertTrue($agent->hasCompanyPermission((int) $co->id, 'offers.create'));
        $this->assertFalse($agent->hasCompanyPermission((int) $co->id, 'bookings.view'));
        $this->assertSame(['offers.create'], $agent->effectivePermissionsForCompany((int) $co->id));
    }

    // ─── PUT endpoint ───────────────────────────────────────────────────

    public function test_company_admin_can_deny_employee_role_permission(): void
    {
        $this->seedRbac();
        $co = $this->company();
        $admin = $this->member('company_admin', $co);
        $agent = $this->member('agent', $co);

        Sanctum::actingAs($admin);
        $res = $this->putJson("/api/companies/{$co->id}/users/{$agent->id}/permissions", [
            'permissions' => [
                ['permission_id' => $this->perms['bookings.view']->id, 'granted' => false],
            ],
        ]);

        $res->assertStatus(200);
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $agent->id, 'company_id' => $co->id,
            'permission_id' => $this->perms['bookings.view']->id, 'effect' => 'deny',
        ]);
        $this->assertFalse($agent->fresh()->hasCompanyPermission((int) $co->id, 'bookings.view'));
    }

    public function test_company_admin_can_allow_extra_permission(): void
    {
        $this->seedRbac();
        $co = $this->company();
        $admin = $this->member('company_admin', $co);
        $agent = $this->member('agent', $co);

        Sanctum::actingAs($admin);
        $res = $this->putJson("/api/companies/{$co->id}/users/{$agent->id}/permissions", [
            'permissions' => [
                ['permission_id' => $this->perms['offers.create']->id, 'granted' => true],
            ],
        ]);

        $res->assertStatus(200);
        $this->assertTrue($agent->fresh()->hasCompanyPermission((int) $co->id, 'offers.create'));
    }

    public function test_setting_to_role_baseline_drops_the_override_row(): void
    {
        $this->seedRbac();
        $co = $this->company();
        $admin = $this->member('company_admin', $co);
        $agent = $this->member('agent', $co);

        // Pre-existing deny on a role perm.
        UserPermissionOverride::create([
            'user_id' => $agent->id, 'company_id' => $co->id,
            'permission_id' => $this->perms['bookings.view']->id, 'effect' => 'deny',
        ]);

        Sanctum::actingAs($admin);
        $this->putJson("/api/companies/{$co->id}/users/{$agent->id}/permissions", [
            'permissions' => [
                ['permission_id' => $this->perms['bookings.view']->id, 'granted' => true],
            ],
        ])->assertStatus(200);

        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $agent->id, 'company_id' => $co->id,
            'permission_id' => $this->perms['bookings.view']->id,
        ]);
    }

    // ─── ceiling / rank / isolation guards ──────────────────────────────

    public function test_ceiling_rejects_permission_caller_lacks(): void
    {
        $this->seedRbac();
        $co = $this->company();
        $operator = $this->member('company_operator', $co); // lacks cars.delete
        $agent = $this->member('agent', $co);

        Sanctum::actingAs($operator);
        $this->putJson("/api/companies/{$co->id}/users/{$agent->id}/permissions", [
            'permissions' => [
                ['permission_id' => $this->perms['cars.delete']->id, 'granted' => true],
            ],
        ])->assertStatus(403);
    }

    public function test_rank_rejects_editing_higher_ranked_employee(): void
    {
        $this->seedRbac();
        $co = $this->company();
        $operator = $this->member('company_operator', $co); // rank 2
        $boss = $this->member('company_admin', $co);        // rank 3

        Sanctum::actingAs($operator);
        $this->getJson("/api/companies/{$co->id}/users/{$boss->id}/permissions")
            ->assertStatus(403);
    }

    public function test_cannot_edit_employee_of_other_company(): void
    {
        $this->seedRbac();
        $coA = $this->company();
        $coB = $this->company();
        $admin = $this->member('company_admin', $coA);
        $outsider = $this->member('agent', $coB);

        Sanctum::actingAs($admin);
        $this->getJson("/api/companies/{$coA->id}/users/{$outsider->id}/permissions")
            ->assertStatus(404);
    }

    public function test_self_edit_blocked_for_non_super(): void
    {
        $this->seedRbac();
        $co = $this->company();
        $admin = $this->member('company_admin', $co);

        Sanctum::actingAs($admin);
        $this->getJson("/api/companies/{$co->id}/users/{$admin->id}/permissions")
            ->assertStatus(403);
    }

    public function test_super_admin_can_grant_any_assignable_permission(): void
    {
        $this->seedRbac();
        $co = $this->company();
        $super = $this->member('super_admin', $co);
        $agent = $this->member('agent', $co);

        Sanctum::actingAs($super);
        $this->putJson("/api/companies/{$co->id}/users/{$agent->id}/permissions", [
            'permissions' => [
                ['permission_id' => $this->perms['cars.delete']->id, 'granted' => true],
            ],
        ])->assertStatus(200);

        $this->assertTrue($agent->fresh()->hasCompanyPermission((int) $co->id, 'cars.delete'));
    }

    public function test_platform_and_escalation_permissions_are_not_assignable(): void
    {
        $this->seedRbac();
        $co = $this->company();
        $super = $this->member('super_admin', $co);
        $agent = $this->member('agent', $co);

        Sanctum::actingAs($super);
        $res = $this->getJson("/api/companies/{$co->id}/users/{$agent->id}/permissions");
        $res->assertStatus(200);

        $names = array_column($res->json('data.permissions'), 'name');
        $this->assertNotContains('super_admin', $names);
        $this->assertNotContains('platform.settings.manage', $names);
        $this->assertContains('bookings.view', $names);

        // And a direct attempt to grant one is rejected by the ceiling.
        $this->putJson("/api/companies/{$co->id}/users/{$agent->id}/permissions", [
            'permissions' => [
                ['permission_id' => $this->perms['super_admin']->id, 'granted' => true],
            ],
        ])->assertStatus(403);
    }

    // ─── /me payload projection ─────────────────────────────────────────

    public function test_me_payload_reflects_deny_override(): void
    {
        $this->seedRbac();
        $co = $this->company();
        $agent = $this->member('agent', $co);

        Sanctum::actingAs($agent);
        $before = $this->getJson('/api/account/me');
        $before->assertStatus(200);
        $this->assertContains('bookings.view', $before->json('data.permissions'));

        UserPermissionOverride::create([
            'user_id' => $agent->id, 'company_id' => $co->id,
            'permission_id' => $this->perms['bookings.view']->id, 'effect' => 'deny',
        ]);

        // Re-bind a fresh instance: actingAs reuses the object, whose
        // relations were cached empty by the first /me call. Each real
        // request resolves the user fresh, so this only matters in-test.
        Sanctum::actingAs($agent->fresh());
        $after = $this->getJson('/api/account/me');
        $after->assertStatus(200);
        $this->assertNotContains('bookings.view', $after->json('data.permissions'));
    }
}

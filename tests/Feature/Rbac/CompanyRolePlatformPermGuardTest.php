<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap 10.06 §7 — company-scoped roles must never hold platform.* (or
 * escalator) permissions. AdminAccessService::isPlatformAdmin() treats ANY
 * platform.* grant as a full platform-staff unlock, so one ticked "Platform
 * stats" checkbox in the RBAC tree silently promoted every operator/agent to
 * ZULU staff and bypassed tenant scoping (found live on company_admin AND
 * agent, 2026-06-10, after the 2026-06-05 strip migration — the RBAC save
 * endpoint kept re-attaching it).
 */
class CompanyRolePlatformPermGuardTest extends TestCase
{
    use RefreshDatabase;

    private Role $agentRole;

    private Role $platformRole;

    private Permission $platformStats;

    private Permission $bookingsView;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agentRole = Role::updateOrCreate(['name' => 'agent'], ['scope' => Role::SCOPE_COMPANY]);
        $this->platformRole = Role::updateOrCreate(['name' => 'platform_admin'], ['scope' => Role::SCOPE_PLATFORM]);
        Role::updateOrCreate(['name' => 'super_admin'], ['scope' => Role::SCOPE_PLATFORM]);

        $this->platformStats = Permission::firstOrCreate(['name' => 'platform.stats.view']);
        $this->bookingsView = Permission::firstOrCreate(['name' => 'bookings.view']);
    }

    private function actAsSuper(): void
    {
        $company = Company::create(['name' => 'ZULU HQ', 'type' => 'operator']);
        $super = User::factory()->create();
        UserCompany::create([
            'user_id' => $super->id,
            'company_id' => $company->id,
            'role_id' => (int) Role::query()->where('name', 'super_admin')->value('id'),
        ]);
        Sanctum::actingAs($super->fresh());
    }

    private function roleHas(Role $role, string $permissionName): bool
    {
        return $role->fresh()->permissions()->where('name', $permissionName)->exists();
    }

    public function test_sync_drops_platform_perms_for_company_scoped_role(): void
    {
        $this->actAsSuper();

        $this->putJson("/api/platform-admin/rbac/roles/{$this->agentRole->id}/permissions", [
            'permission_ids' => [$this->platformStats->id, $this->bookingsView->id],
        ])->assertOk();

        $this->assertTrue($this->roleHas($this->agentRole, 'bookings.view'));
        $this->assertFalse($this->roleHas($this->agentRole, 'platform.stats.view'));
    }

    public function test_sync_sheds_preexisting_escalator_pollution_on_save(): void
    {
        $this->actAsSuper();

        // platform.admin is NOT tree-managed, so the P4 "preserve non-tree
        // perms" logic used to carry it through every save — the §7 guard must
        // shed it for company-scoped roles.
        $escalator = Permission::firstOrCreate(['name' => 'platform.admin']);
        $this->agentRole->permissions()->syncWithoutDetaching([$escalator->id]);

        $this->putJson("/api/platform-admin/rbac/roles/{$this->agentRole->id}/permissions", [
            'permission_ids' => [$this->bookingsView->id],
        ])->assertOk();

        $this->assertTrue($this->roleHas($this->agentRole, 'bookings.view'));
        $this->assertFalse($this->roleHas($this->agentRole, 'platform.admin'));
    }

    public function test_platform_scoped_role_keeps_platform_perms(): void
    {
        $this->actAsSuper();

        $this->putJson("/api/platform-admin/rbac/roles/{$this->platformRole->id}/permissions", [
            'permission_ids' => [$this->platformStats->id],
        ])->assertOk();

        $this->assertTrue($this->roleHas($this->platformRole, 'platform.stats.view'));
    }

    public function test_store_role_filters_platform_ids_for_company_scope(): void
    {
        $this->actAsSuper();

        $response = $this->postJson('/api/platform-admin/rbac/roles', [
            'name' => 'test_branch_manager',
            'scope' => 'company',
            'permission_ids' => [$this->platformStats->id, $this->bookingsView->id],
        ])->assertCreated();

        $role = Role::query()->findOrFail($response->json('data.id'));
        $this->assertTrue($this->roleHas($role, 'bookings.view'));
        $this->assertFalse($this->roleHas($role, 'platform.stats.view'));
    }

    public function test_demoting_role_to_company_scope_sheds_platform_perms(): void
    {
        $this->actAsSuper();

        $role = Role::create(['name' => 'test_demoted', 'scope' => Role::SCOPE_PLATFORM]);
        $role->permissions()->sync([$this->platformStats->id, $this->bookingsView->id]);

        $this->patchJson("/api/platform-admin/rbac/roles/{$role->id}", [
            'scope' => 'company',
        ])->assertOk();

        $this->assertTrue($this->roleHas($role, 'bookings.view'));
        $this->assertFalse($this->roleHas($role, 'platform.stats.view'));
    }
}

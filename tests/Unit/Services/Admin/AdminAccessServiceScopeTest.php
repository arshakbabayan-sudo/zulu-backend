<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Admin;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use App\Services\Admin\AdminAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 of the RBAC tenant-scoping blueprint (2026-06-02) — verifies the
 * unified resolver helpers that future phases (3, 4, 5) build on:
 *
 *   - isPlatformStaff(user): genuine ZULU platform staff (platform_admin role)
 *     vs an operator that merely carries a `platform.*` permission via its
 *     operator_admin / company_admin role. Both make isPlatformAdmin()=true
 *     (gates page access), but only the former is platform STAFF for
 *     tenant-scope decisions.
 *
 *   - visibleCompanyIds(user): single source of truth for "which companies'
 *     rows may this caller see across the admin panel" (Layer A of the
 *     blueprint scope model). super → ALL; platform staff → callerCompanyIds
 *     today (Phase 4 will switch to assignedCompanyIds); everyone else →
 *     callerCompanyIds. Empty list survives as a real [] → 1=0 downstream.
 */
class AdminAccessServiceScopeTest extends TestCase
{
    use RefreshDatabase;

    private AdminAccessService $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->access = new AdminAccessService;
        $this->seedRoles();
    }

    public function test_is_platform_staff_true_for_super_admin(): void
    {
        $user = $this->userWithRole('super_admin');
        $this->assertTrue($this->access->isPlatformStaff($user));
    }

    public function test_is_platform_staff_true_for_platform_admin_role(): void
    {
        $user = $this->userWithRole('platform_admin');
        $this->assertTrue($this->access->isPlatformStaff($user));
    }

    public function test_is_platform_staff_false_for_operator_with_platform_permission(): void
    {
        // The key distinction from isPlatformAdmin(): an operator that
        // happens to carry a `platform.*` permission via its operator_admin
        // role is platform-admin for menu/page access but NOT platform-staff
        // for tenant-scope decisions.
        $user = $this->userWithRole('operator_admin', ['platform.companies.list']);
        $this->assertTrue($this->access->isPlatformAdmin($user));
        $this->assertFalse($this->access->isPlatformStaff($user));
    }

    public function test_is_platform_staff_false_for_plain_operator(): void
    {
        $user = $this->userWithRole('operator_admin');
        $this->assertFalse($this->access->isPlatformStaff($user));
    }

    public function test_is_platform_staff_false_for_plain_agent(): void
    {
        $user = $this->userWithRole('agent');
        $this->assertFalse($this->access->isPlatformStaff($user));
    }

    public function test_is_platform_staff_false_for_user_without_membership(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($this->access->isPlatformStaff($user));
    }

    public function test_visible_company_ids_returns_all_for_super_admin(): void
    {
        $extraCompanyIds = [
            $this->makeCompany()->id,
            $this->makeCompany()->id,
            $this->makeCompany()->id,
        ];
        $user = $this->userWithRole('super_admin');

        $visible = $this->access->visibleCompanyIds($user);

        // Should include every company on the platform (the user's own +
        // the extras), regardless of UserCompany memberships.
        foreach ($extraCompanyIds as $id) {
            $this->assertContains($id, $visible);
        }
        $this->assertSame(
            DB::table('companies')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all(),
            $visible,
        );
    }

    public function test_visible_company_ids_returns_own_memberships_for_operator(): void
    {
        $other = $this->makeCompany();
        $user = $this->userWithRole('operator_admin');
        $ownCompanyId = (int) $user->memberships()->first()->company_id;

        $visible = $this->access->visibleCompanyIds($user);

        $this->assertSame([$ownCompanyId], $visible);
        $this->assertNotContains($other->id, $visible);
    }

    public function test_visible_company_ids_empty_for_platform_staff_without_assignment(): void
    {
        // Phase 4: platform staff see ONLY assigned companies. With no
        // platform_staff_scopes rows they see nothing (fail-closed), even
        // though they have their own platform-company membership.
        $this->makeCompany();
        $user = $this->userWithRole('platform_admin');

        $this->assertSame([], $this->access->visibleCompanyIds($user));
    }

    // ─── Phase 4: ՍԱ-staff company/country assignment ───────────────────────

    public function test_assigned_company_ids_resolves_direct_assignment(): void
    {
        $staff = $this->userWithRole('platform_admin');
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        $this->makeCompany(); // unassigned — must not appear

        \App\Models\PlatformStaffScope::query()->create(['user_id' => $staff->id, 'company_id' => $a->id]);
        \App\Models\PlatformStaffScope::query()->create(['user_id' => $staff->id, 'company_id' => $b->id]);

        $this->assertSame(
            collect([$a->id, $b->id])->sort()->values()->all(),
            $this->access->assignedCompanyIds($staff),
        );
        // visibleCompanyIds routes platform staff through the assigned set.
        $this->assertSame(
            collect([$a->id, $b->id])->sort()->values()->all(),
            $this->access->visibleCompanyIds($staff),
        );
    }

    public function test_assigned_company_ids_resolves_country_case_insensitively(): void
    {
        $staff = $this->userWithRole('platform_admin');
        $am1 = $this->makeCompanyInCountry('Armenia');
        $am2 = $this->makeCompanyInCountry('  armenia ');   // messy casing/space
        $eg = $this->makeCompanyInCountry('Egypt');         // different country

        \App\Models\PlatformStaffScope::query()->create(['user_id' => $staff->id, 'country' => 'ARMENIA']);

        $ids = $this->access->assignedCompanyIds($staff);
        $this->assertContains($am1->id, $ids);
        $this->assertContains($am2->id, $ids);
        $this->assertNotContains($eg->id, $ids);
    }

    public function test_assigned_company_ids_empty_without_scopes(): void
    {
        $staff = $this->userWithRole('platform_admin');
        $this->makeCompany();

        $this->assertSame([], $this->access->assignedCompanyIds($staff));
    }

    public function test_visible_company_ids_returns_empty_for_user_without_membership(): void
    {
        $this->makeCompany();
        $user = User::factory()->create();

        $this->assertSame([], $this->access->visibleCompanyIds($user));
    }

    public function test_can_access_admin_panel_true_for_super_admin(): void
    {
        $this->assertTrue($this->access->canAccessAdminPanel($this->userWithRole('super_admin')));
    }

    public function test_can_access_admin_panel_true_for_platform_admin(): void
    {
        $this->assertTrue($this->access->canAccessAdminPanel($this->userWithRole('platform_admin')));
    }

    public function test_can_access_admin_panel_true_for_operator(): void
    {
        // Operator/agent roles no longer carry platform.* perms (R.1 role
        // hygiene 2026-05-28), so isPlatformAdmin is false for them. The
        // admin-panel gate must still let them through — Phase 0/1 + Phase 2
        // controllers will scope their visibility to their own company.
        $this->assertTrue($this->access->canAccessAdminPanel($this->userWithRole('operator_admin')));
        $this->assertTrue($this->access->canAccessAdminPanel($this->userWithRole('company_admin')));
        $this->assertTrue($this->access->canAccessAdminPanel($this->userWithRole('agent')));
    }

    public function test_can_access_admin_panel_false_for_b2c_customer(): void
    {
        // No UserCompany membership = B2C customer (or unverified signup).
        $user = User::factory()->create();
        $this->assertFalse($this->access->canAccessAdminPanel($user));
    }

    // ─── Phase 3: within-company row scope (Layer B) ────────────────────────

    public function test_is_company_owner_true_for_company_admin(): void
    {
        $this->assertTrue($this->access->isCompanyOwner($this->userWithRole('company_admin')));
    }

    public function test_is_company_owner_false_for_employee_roles(): void
    {
        $this->assertFalse($this->access->isCompanyOwner($this->userWithRole('operator_admin')));
        $this->assertFalse($this->access->isCompanyOwner($this->userWithRole('agent')));
    }

    public function test_row_scope_null_for_company_owner(): void
    {
        // Owner sees the whole company → no row filter.
        $owner = $this->userWithRole('company_admin');
        $this->assertNull($this->access->employeeRowScopeUserId($owner, 'bookings.view_all'));
    }

    public function test_row_scope_returns_own_id_for_plain_employee(): void
    {
        // Plain employee without the module's view_all → own rows only.
        $employee = $this->userWithRole('agent');
        $this->assertSame($employee->id, $this->access->employeeRowScopeUserId($employee, 'bookings.view_all'));
    }

    public function test_row_scope_null_for_employee_with_view_all(): void
    {
        // Senior manager: employee role + the module's view_all grant → whole
        // company for THAT module (per-module, blueprint Q1).
        $manager = $this->userWithRole('operator_admin', ['bookings.view_all']);
        $this->assertNull($this->access->employeeRowScopeUserId($manager, 'bookings.view_all'));
        // ...but still own-rows-only for a DIFFERENT module they weren't granted.
        $this->assertSame($manager->id, $this->access->employeeRowScopeUserId($manager, 'crm.view_all'));
    }

    private function seedRoles(): void
    {
        foreach (['super_admin', 'platform_admin', 'operator_admin', 'company_admin', 'agent'] as $name) {
            Role::query()->firstOrCreate(['name' => $name]);
        }
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'ScopeTest '.uniqid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function makeCompanyInCountry(string $country): Company
    {
        return Company::query()->create([
            'name' => 'ScopeTest '.uniqid(),
            'type' => 'operator',
            'status' => 'active',
            'country' => $country,
        ]);
    }

    /**
     * @param  list<string>  $extraPermissions  permission names to attach to the role
     */
    private function userWithRole(string $roleName, array $extraPermissions = []): User
    {
        $user = User::factory()->create();
        $company = $this->makeCompany();
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        foreach ($extraPermissions as $permName) {
            $perm = Permission::query()->firstOrCreate(['name' => $permName]);
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'permission_id' => $perm->id],
                [],
            );
        }

        UserCompany::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
        ]);

        return $user->fresh();
    }
}

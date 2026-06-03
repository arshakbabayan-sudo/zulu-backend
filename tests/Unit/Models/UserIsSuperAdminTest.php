<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1 / Step B.1 — verifies the User::is_super_admin computed
 * accessor and the User::superAdmins() query scope behave correctly
 * with the new roles.scope column.
 *
 * Replaces the broken `$users->where('is_super_admin', true)` SQL
 * pattern that errored on a non-existent column (audit doc §6).
 */
class UserIsSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Direct DB insert — Company model has no factory in this codebase
     * (Phase 1 audit). Returns a Company-shaped object with `id` set.
     */
    private function makeCompany(): object
    {
        $id = DB::table('companies')->insertGetId([
            'name' => 'TestCo '.uniqid(),
            'type' => 'operator',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (object) ['id' => $id];
    }

    private function seedRoles(): void
    {
        // Match the production seed exactly (per audit §7 + B.0 migration).
        $base = [
            'super_admin' => 'platform',
            'platform_admin' => 'platform',
            'operator_admin' => 'platform',
            'company_admin' => 'company',
            'agent' => 'company',
        ];
        foreach ($base as $name => $scope) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name],
                ['name' => $name, 'scope' => $scope, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function test_user_without_membership_is_not_super_admin(): void
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $this->assertFalse($user->is_super_admin);
    }

    public function test_user_with_super_admin_role_is_super_admin(): void
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $company = $this->makeCompany();
        $roleId = DB::table('roles')->where('name', 'super_admin')->value('id');

        UserCompany::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $roleId,
        ]);

        $user->refresh();
        $this->assertTrue($user->is_super_admin);
    }

    public function test_user_with_platform_admin_role_is_not_super_admin(): void
    {
        // Defensive: platform_admin is platform-scoped but NOT super_admin.
        $this->seedRoles();
        $user = User::factory()->create();
        $company = $this->makeCompany();
        $roleId = DB::table('roles')->where('name', 'platform_admin')->value('id');

        UserCompany::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $roleId,
        ]);

        $user->refresh();
        $this->assertFalse(
            $user->is_super_admin,
            'platform_admin is platform-scoped but is NOT the super_admin role'
        );
    }

    public function test_user_with_company_admin_role_is_not_super_admin(): void
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $company = $this->makeCompany();
        $roleId = DB::table('roles')->where('name', 'company_admin')->value('id');

        UserCompany::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $roleId,
        ]);

        $user->refresh();
        $this->assertFalse($user->is_super_admin);
    }

    public function test_dynamic_attr_setter_overrides_for_test_fixtures(): void
    {
        // Test-fixture pattern used in AdminBulkNotificationTest:192 etc.
        $this->seedRoles();
        $user = User::factory()->create();
        $user->is_super_admin = true;

        $this->assertTrue(
            $user->is_super_admin,
            'Dynamic in-memory attribute set should be readable as super-admin'
        );
    }

    public function test_super_admins_query_scope_returns_only_super_admins(): void
    {
        $this->seedRoles();
        $superRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');
        $platformRoleId = DB::table('roles')->where('name', 'platform_admin')->value('id');

        $superAdmin = User::factory()->create();
        $platformAdmin = User::factory()->create();
        $noRole = User::factory()->create();
        $company = $this->makeCompany();

        UserCompany::create([
            'user_id' => $superAdmin->id,
            'company_id' => $company->id,
            'role_id' => $superRoleId,
        ]);
        UserCompany::create([
            'user_id' => $platformAdmin->id,
            'company_id' => $company->id,
            'role_id' => $platformRoleId,
        ]);

        $ids = User::query()->superAdmins()->pluck('id')->all();

        $this->assertContains($superAdmin->id, $ids);
        $this->assertNotContains($platformAdmin->id, $ids);
        $this->assertNotContains($noRole->id, $ids);
    }

    public function test_super_admins_query_scope_count_works(): void
    {
        // Replicates the exact pattern used at AdminRbacController:128.
        $this->seedRoles();
        $superRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        User::factory()->create();
        $company = $this->makeCompany();
        UserCompany::create([
            'user_id' => $u1->id,
            'company_id' => $company->id,
            'role_id' => $superRoleId,
        ]);
        UserCompany::create([
            'user_id' => $u2->id,
            'company_id' => $company->id,
            'role_id' => $superRoleId,
        ]);

        $count = User::query()->superAdmins()->count();
        $this->assertSame(2, $count);
    }
}

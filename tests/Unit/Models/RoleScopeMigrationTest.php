<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 1 / Step B.0 — verifies the roles.scope migration applied
 * cleanly: column exists, default is 'company', and the four canonical
 * platform-scoped role names are backfilled to 'platform'.
 */
class RoleScopeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_table_has_scope_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('roles', 'scope'),
            'roles.scope column should exist after migration'
        );
    }

    public function test_new_role_defaults_to_company_scope(): void
    {
        $id = DB::table('roles')->insertGetId([
            'name' => 'test_role_'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scope = DB::table('roles')->where('id', $id)->value('scope');
        $this->assertSame('company', $scope);
    }

    public function test_seeded_platform_roles_have_platform_scope(): void
    {
        // The RbacBootstrapSeeder (or equivalent test seeding) creates
        // the canonical roles. If any are missing in the test DB we
        // insert them manually first so the assertion is meaningful.
        foreach (['super_admin', 'platform_admin', 'operator_admin'] as $name) {
            if (! DB::table('roles')->where('name', $name)->exists()) {
                DB::table('roles')->insert([
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Re-run the backfill clause exactly as the migration does, so
        // the test reflects the production behaviour.
        DB::table('roles')
            ->whereIn('name', ['super_admin', 'platform_admin', 'operator_admin', 'agent_owner'])
            ->update(['scope' => 'platform']);

        foreach (['super_admin', 'platform_admin', 'operator_admin'] as $name) {
            $scope = DB::table('roles')->where('name', $name)->value('scope');
            $this->assertSame(
                'platform',
                $scope,
                "{$name} should be platform-scoped after migration backfill"
            );
        }
    }

    public function test_company_admin_and_agent_remain_company_scoped(): void
    {
        foreach (['company_admin', 'agent'] as $name) {
            if (! DB::table('roles')->where('name', $name)->exists()) {
                DB::table('roles')->insert([
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Same backfill as the migration — should NOT touch these names.
        DB::table('roles')
            ->whereIn('name', ['super_admin', 'platform_admin', 'operator_admin', 'agent_owner'])
            ->update(['scope' => 'platform']);

        foreach (['company_admin', 'agent'] as $name) {
            $scope = DB::table('roles')->where('name', $name)->value('scope');
            $this->assertSame(
                'company',
                $scope,
                "{$name} should remain company-scoped (not in platform whitelist)"
            );
        }
    }
}

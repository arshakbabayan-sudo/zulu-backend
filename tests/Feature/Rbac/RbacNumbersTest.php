<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RBAC numbers unification (2026-07-06) — the role table, the stat cards and
 * the permission drawer must tell ONE consistent story:
 *
 *  - GET rbac/roles now returns per-role tree_granted_count / tree_total_count
 *    computed with EXACTLY the drawer's semantics (RbacMenuTree.tsx): only
 *    tree-managed permissions count, and for company-scoped roles the
 *    platform-only ones (name starts "platform." or equals "super_admin") are
 *    excluded from both numerator and denominator.
 *  - GET rbac/stats now returns members_distinct (people, not pivot rows) and
 *    tree_permissions_total (seeded tree-managed permissions).
 *  - The consumed Layer-B `*.view_all` permissions joined the tree; the DEAD
 *    `hr.view` grants are detached (2026_07_06_000300) and the seeder no
 *    longer re-creates them. Existing response fields stay untouched.
 */
class RbacNumbersTest extends TestCase
{
    use RefreshDatabase;

    private Role $agentRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agentRole = Role::updateOrCreate(['name' => 'agent'], ['scope' => Role::SCOPE_COMPANY]);
        Role::updateOrCreate(['name' => 'super_admin'], ['scope' => Role::SCOPE_PLATFORM]);
    }

    /** Authenticate as a platform super admin (same pattern as the §7 guard test). */
    private function actAsSuper(): User
    {
        $company = Company::create(['name' => 'ZULU HQ', 'type' => 'operator']);
        $super = User::factory()->create();
        UserCompany::create([
            'user_id' => $super->id,
            'company_id' => $company->id,
            'role_id' => (int) Role::query()->where('name', 'super_admin')->value('id'),
        ]);
        Sanctum::actingAs($super->fresh());

        return $super;
    }

    /** Flatten the tree endpoint payload to its permission nodes. */
    private function treePermissionNodes(array $sections): array
    {
        $nodes = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                foreach ($item['permissions'] as $p) {
                    $nodes[] = $p;
                }
            }
        }

        return $nodes;
    }

    public function test_stats_returns_members_distinct_and_tree_permissions_total(): void
    {
        $this->actAsSuper(); // 1 membership

        // One PERSON with two memberships — must count once in members_distinct
        // but twice in total_memberships.
        $companyB = Company::create(['name' => 'B Travel', 'type' => 'operator']);
        $companyC = Company::create(['name' => 'C Travel', 'type' => 'agency']);
        $dual = User::factory()->create();
        UserCompany::create(['user_id' => $dual->id, 'company_id' => $companyB->id, 'role_id' => $this->agentRole->id]);
        UserCompany::create(['user_id' => $dual->id, 'company_id' => $companyC->id, 'role_id' => $this->agentRole->id]);

        $res = $this->getJson('/api/platform-admin/rbac/stats')->assertOk();

        // Existing fields untouched (additive change).
        $res->assertJsonStructure(['data' => [
            'total_roles', 'total_permissions', 'total_memberships', 'super_admins',
            'members_distinct', 'tree_permissions_total',
        ]]);

        $this->assertSame(3, $res->json('data.total_memberships'));
        $this->assertSame(2, $res->json('data.members_distinct'));

        // tree_permissions_total must equal what the drawer actually renders:
        // the distinct permission ids the tree endpoint returns.
        $sections = $this->getJson('/api/platform-admin/rbac/tree')->assertOk()->json('data.sections');
        $distinctIds = [];
        foreach ($this->treePermissionNodes($sections) as $p) {
            $distinctIds[$p['id']] = true;
        }
        $this->assertGreaterThan(0, count($distinctIds));
        $this->assertSame(count($distinctIds), $res->json('data.tree_permissions_total'));
    }

    public function test_tree_exposes_consumed_view_all_permissions_but_never_escalators(): void
    {
        $this->actAsSuper();

        $sections = $this->getJson('/api/platform-admin/rbac/tree')->assertOk()->json('data.sections');
        $names = array_column($this->treePermissionNodes($sections), 'name');

        // CONSUMED Layer-B row-scope permissions (AdminAccessService::
        // employeeRowScopeUserId call sites) are now visible/manageable.
        foreach (['bookings.view_all', 'package_orders.view_all', 'crm.view_all', 'contracts.view_all'] as $name) {
            $this->assertContains($name, $names);
        }

        // The escalators stay hidden by design; dead hr.view never joined.
        foreach (['super_admin', 'platform.admin', 'platform.manage', 'hr.view'] as $name) {
            $this->assertNotContains($name, $names);
        }
    }

    public function test_agent_role_tree_counts_match_drawer_computation(): void
    {
        $this->actAsSuper();

        // Known grant set: 2 company-eligible tree perms (bookings.view,
        // bookings.view_all) + 1 non-tree orphan (hr.view) + 1 platform-only
        // tree perm (platform.stats.view — §7 forbids it on company roles, but
        // pollution must not distort the counts either way).
        $grantIds = [];
        foreach (['bookings.view', 'bookings.view_all', 'hr.view', 'platform.stats.view'] as $name) {
            $grantIds[] = Permission::firstOrCreate(['name' => $name])->id;
        }
        $this->agentRole->permissions()->sync($grantIds);

        $roles = $this->getJson('/api/platform-admin/rbac/roles')->assertOk()->json('data');
        $agent = collect($roles)->firstWhere('name', 'agent');
        $this->assertNotNull($agent);

        // Recompute the DRAWER's numbers from the tree endpoint using the exact
        // RbacMenuTree.tsx company-scope filter (isPlatformOnlyPerm).
        $sections = $this->getJson('/api/platform-admin/rbac/tree?role_id='.$this->agentRole->id)
            ->assertOk()
            ->json('data.sections');
        $total = 0;
        $granted = 0;
        foreach ($this->treePermissionNodes($sections) as $p) {
            if (str_starts_with($p['name'], 'platform.') || $p['name'] === 'super_admin') {
                continue; // hidden for company-scoped roles
            }
            $total++;
            if ($p['granted']) {
                $granted++;
            }
        }

        $this->assertSame($total, $agent['tree_total_count']);
        $this->assertSame($granted, $agent['tree_granted_count']);

        // And the absolute values: only bookings.view + bookings.view_all are
        // tree-managed AND company-eligible.
        $this->assertSame(2, $agent['tree_granted_count']);

        // Existing fields still present and untouched by the new ones.
        $this->assertSame(4, count($agent['permissions']));
        $this->assertArrayHasKey('memberships_count', $agent);
    }

    public function test_platform_scope_denominator_includes_platform_tree_perms(): void
    {
        $this->actAsSuper();

        // Ensure at least one platform-only tree permission exists so the two
        // denominators genuinely differ.
        Permission::firstOrCreate(['name' => 'platform.stats.view']);

        $roles = $this->getJson('/api/platform-admin/rbac/roles')->assertOk()->json('data');
        $superRow = collect($roles)->firstWhere('name', 'super_admin');
        $agentRow = collect($roles)->firstWhere('name', 'agent');

        $stats = $this->getJson('/api/platform-admin/rbac/stats')->assertOk()->json('data');

        // Platform-scoped role sees the WHOLE tree (= the Permissions stat card).
        $this->assertSame($stats['tree_permissions_total'], $superRow['tree_total_count']);
        // Company-scoped role sees strictly fewer (platform-only perms hidden).
        $this->assertLessThan($superRow['tree_total_count'], $agentRow['tree_total_count']);
    }

    public function test_migrations_leave_no_role_grants_for_dead_hr_view(): void
    {
        // 2026_07_06_000300 detaches hr.view from every role; nothing after it
        // may re-grant. (Per-user UserPermissionOverride rows are untouched.)
        $count = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.name', 'hr.view')
            ->count();

        $this->assertSame(0, $count);
    }

    public function test_seeder_no_longer_grants_hr_view_to_working_roles(): void
    {
        $this->seed(RbacBootstrapSeeder::class);

        // super_admin keeps sync($allIds) by definition (the "everything" role);
        // the working roles must not get the dead grant back on a fresh seed.
        $count = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('permissions.name', 'hr.view')
            ->whereIn('roles.name', ['platform_admin', 'company_admin', 'agent'])
            ->count();

        $this->assertSame(0, $count);

        // The permission ROW itself is preserved (detach ≠ delete).
        $this->assertTrue(Permission::query()->where('name', 'hr.view')->exists());
    }
}

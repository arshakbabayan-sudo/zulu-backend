<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlatformStaffScope;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RBAC blueprint Phase 4 — super-admin manages a platform-staff user's
 * company/country assignments. GET + PUT under /platform-admin/staff/{user}/scopes.
 */
class PlatformStaffScopeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'platform_admin', 'operator_admin'] as $name) {
            Role::query()->firstOrCreate(['name' => $name]);
        }
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $company = Company::query()->create(['name' => 'Co '.uniqid(), 'type' => 'operator', 'status' => 'active']);
        $user->companies()->attach($company->id, ['role_id' => Role::query()->where('name', $roleName)->value('id')]);

        return $user->fresh();
    }

    public function test_non_super_is_forbidden(): void
    {
        $operator = $this->userWithRole('operator_admin');
        $staff = $this->userWithRole('platform_admin');
        Sanctum::actingAs($operator);

        $this->getJson("/api/platform-admin/staff/{$staff->id}/scopes")->assertStatus(403);
        $this->putJson("/api/platform-admin/staff/{$staff->id}/scopes", ['company_ids' => []])->assertStatus(403);
    }

    public function test_super_can_assign_and_read_back_scopes(): void
    {
        $super = $this->userWithRole('super_admin');
        $staff = $this->userWithRole('platform_admin');
        $a = Company::query()->create(['name' => 'Assign A', 'type' => 'operator', 'status' => 'active', 'country' => 'Armenia']);
        $b = Company::query()->create(['name' => 'Assign B', 'type' => 'operator', 'status' => 'active', 'country' => 'Egypt']);

        Sanctum::actingAs($super);

        $this->putJson("/api/platform-admin/staff/{$staff->id}/scopes", [
            'company_ids' => [$a->id],
            'countries' => ['Egypt'],
        ])->assertOk();

        $this->assertDatabaseHas('platform_staff_scopes', ['user_id' => $staff->id, 'company_id' => $a->id]);
        $this->assertDatabaseHas('platform_staff_scopes', ['user_id' => $staff->id, 'country' => 'Egypt']);

        $res = $this->getJson("/api/platform-admin/staff/{$staff->id}/scopes")->assertOk();
        $res->assertJsonPath('data.company_ids', [$a->id]);
        $res->assertJsonPath('data.countries', ['Egypt']);
        // resolved = direct A + every Egypt company (b). Both present.
        $resolved = $res->json('data.resolved_company_ids');
        $this->assertContains($a->id, $resolved);
        $this->assertContains($b->id, $resolved);
    }

    public function test_put_replaces_previous_scopes(): void
    {
        $super = $this->userWithRole('super_admin');
        $staff = $this->userWithRole('platform_admin');
        $a = Company::query()->create(['name' => 'Repl A', 'type' => 'operator', 'status' => 'active']);
        $c = Company::query()->create(['name' => 'Repl C', 'type' => 'operator', 'status' => 'active']);

        PlatformStaffScope::query()->create(['user_id' => $staff->id, 'company_id' => $a->id]);

        Sanctum::actingAs($super);
        $this->putJson("/api/platform-admin/staff/{$staff->id}/scopes", ['company_ids' => [$c->id]])->assertOk();

        // Old A row gone, only C remains.
        $this->assertDatabaseMissing('platform_staff_scopes', ['user_id' => $staff->id, 'company_id' => $a->id]);
        $this->assertDatabaseHas('platform_staff_scopes', ['user_id' => $staff->id, 'company_id' => $c->id]);
        $this->assertSame(1, PlatformStaffScope::query()->where('user_id', $staff->id)->count());
    }
}

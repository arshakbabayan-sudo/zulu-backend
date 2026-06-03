<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModulePermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6A feature tests — per-company admin module visibility.
 *
 * Routes covered:
 *   GET   /api/companies/{company}/module-permissions
 *   PATCH /api/companies/{company}/module-permissions
 *
 * Acceptance criteria from the controller doc-block + roadmap notes:
 *   - Only super admins can read/write (everyone else gets 403)
 *   - GET returns the canonical module-key list + the company's explicit rows
 *   - PATCH upserts rows (sparse map of {module_key, is_allowed, notes})
 *   - PATCH is idempotent — re-posting the same payload doesn't duplicate rows
 *   - PATCH updates an existing row in place rather than creating a new one
 */
class CompanyModulePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_is_rejected(): void
    {
        $company = $this->makeCompany();

        $this->getJson("/api/companies/{$company->id}/module-permissions")
            ->assertStatus(401);
    }

    public function test_non_super_admin_caller_is_forbidden(): void
    {
        $company = $this->makeCompany();
        $regularUser = $this->makeUser();
        Sanctum::actingAs($regularUser);

        $this->getJson("/api/companies/{$company->id}/module-permissions")
            ->assertStatus(403);

        $this->patchJson("/api/companies/{$company->id}/module-permissions", [
            'permissions' => [['module_key' => 'ops.reviews', 'is_allowed' => false]],
        ])->assertStatus(403);
    }

    public function test_index_returns_empty_state_with_canonical_module_keys(): void
    {
        $company = $this->makeCompany();
        Sanctum::actingAs($this->makeSuperAdmin());

        $response = $this->getJson("/api/companies/{$company->id}/module-permissions");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.company_id', $company->id);
        $response->assertJsonPath('data.permissions', []);
        $response->assertJsonPath(
            'data.available_module_keys',
            CompanyModulePermission::MODULE_KEYS
        );
    }

    public function test_patch_inserts_new_permission_rows(): void
    {
        $company = $this->makeCompany();
        Sanctum::actingAs($this->makeSuperAdmin());

        $availableKeys = CompanyModulePermission::MODULE_KEYS;
        $keyToDeny = $availableKeys[0];
        $keyToAllow = $availableKeys[1] ?? $availableKeys[0];

        $payload = [
            'permissions' => [
                ['module_key' => $keyToDeny, 'is_allowed' => false, 'notes' => 'temporarily off'],
                ['module_key' => $keyToAllow, 'is_allowed' => true],
            ],
        ];

        $response = $this->patchJson(
            "/api/companies/{$company->id}/module-permissions",
            $payload
        );

        $response->assertOk();
        $this->assertDatabaseCount('company_module_permissions', 2);
        $this->assertDatabaseHas('company_module_permissions', [
            'company_id' => $company->id,
            'module_key' => $keyToDeny,
            'is_allowed' => false,
        ]);
    }

    public function test_patch_updates_existing_row_in_place(): void
    {
        $company = $this->makeCompany();
        Sanctum::actingAs($this->makeSuperAdmin());

        $key = CompanyModulePermission::MODULE_KEYS[0];

        // First patch — deny
        $this->patchJson("/api/companies/{$company->id}/module-permissions", [
            'permissions' => [['module_key' => $key, 'is_allowed' => false]],
        ])->assertOk();

        // Second patch — flip to allow
        $this->patchJson("/api/companies/{$company->id}/module-permissions", [
            'permissions' => [['module_key' => $key, 'is_allowed' => true]],
        ])->assertOk();

        // Should still be exactly one row for this (company, module_key) pair.
        $this->assertDatabaseCount('company_module_permissions', 1);
        $this->assertDatabaseHas('company_module_permissions', [
            'company_id' => $company->id,
            'module_key' => $key,
            'is_allowed' => true,
        ]);
    }

    public function test_patch_requires_non_empty_permissions_array(): void
    {
        $company = $this->makeCompany();
        Sanctum::actingAs($this->makeSuperAdmin());

        $this->patchJson("/api/companies/{$company->id}/module-permissions", [
            'permissions' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['permissions']);

        $this->patchJson("/api/companies/{$company->id}/module-permissions", [
            'permissions' => [['module_key' => 'x']],
        ])->assertStatus(422)->assertJsonValidationErrors(['permissions.0.is_allowed']);
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Phase 6A '.str()->uuid(),
            'type' => 'operator',
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Phase 6A Test',
            'email' => 'phase6a-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function makeSuperAdmin(): User
    {
        $user = $this->makeUser();
        $role = Role::query()->firstOrCreate(['name' => 'super_admin']);
        $platformCompany = $this->makeCompany();
        $user->companies()->attach($platformCompany->id, ['role_id' => $role->id]);

        return $user;
    }
}

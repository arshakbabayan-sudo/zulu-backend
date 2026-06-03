<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\ServiceCatalogItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7.12 feature tests — service catalog CRUD endpoints.
 *
 * Routes:
 *   GET    /api/service-catalog
 *   POST   /api/service-catalog
 *   PATCH  /api/service-catalog/{id}
 *   DELETE /api/service-catalog/{id}
 *
 * Visibility: company-scoped for non-super-admins, full visibility for
 * super admins. Cross-company update/delete is rejected as 404.
 */
class ServiceCatalogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_is_rejected(): void
    {
        $this->getJson('/api/service-catalog')->assertStatus(401);
    }

    public function test_index_is_company_scoped(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $userA = $this->makeUserForCompany($companyA);

        ServiceCatalogItem::query()->create([
            'company_id' => $companyA->id,
            'name' => 'Airport pickup',
            'is_active' => true,
            'created_by_user_id' => $userA->id,
        ]);
        ServiceCatalogItem::query()->create([
            'company_id' => $companyB->id,
            'name' => 'Wedding planning',
            'is_active' => true,
            'created_by_user_id' => $userA->id,
        ]);

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/service-catalog');
        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Airport pickup', $names);
        $this->assertNotContains('Wedding planning', $names);
    }

    public function test_store_creates_item_for_user_company(): void
    {
        $company = $this->makeCompany();
        Sanctum::actingAs($this->makeUserForCompany($company));

        $response = $this->postJson('/api/service-catalog', [
            'name' => 'Honeymoon package consult',
            'category' => 'concierge',
            'description' => '1-on-1 itinerary build session.',
            'base_price' => 75.5,
            'currency' => 'usd',
            'unit' => 'per_person',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Honeymoon package consult');
        // Currency should be uppercased server-side.
        $response->assertJsonPath('data.currency', 'USD');
        $response->assertJsonPath('data.is_active', true); // default

        $this->assertDatabaseHas('service_catalog_items', [
            'company_id' => $company->id,
            'name' => 'Honeymoon package consult',
            'currency' => 'USD',
        ]);
    }

    public function test_store_requires_user_to_have_company(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/service-catalog', ['name' => 'Orphan service'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No active company.');
    }

    public function test_store_validates_unit_enum(): void
    {
        $company = $this->makeCompany();
        Sanctum::actingAs($this->makeUserForCompany($company));

        $this->postJson('/api/service-catalog', [
            'name' => 'Bad unit service',
            'unit' => 'per_quantum',
        ])->assertStatus(422)->assertJsonValidationErrors(['unit']);
    }

    public function test_update_modifies_owned_item(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        $row = ServiceCatalogItem::query()->create([
            'company_id' => $company->id,
            'name' => 'Original',
            'is_active' => true,
            'created_by_user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/service-catalog/{$row->id}", [
            'name' => 'Renamed',
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_update_cross_company_returns_404(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $userB = $this->makeUserForCompany($companyB);
        $row = ServiceCatalogItem::query()->create([
            'company_id' => $companyA->id,
            'name' => 'Not yours',
            'is_active' => true,
            'created_by_user_id' => $this->makeUser()->id,
        ]);

        Sanctum::actingAs($userB);

        $this->patchJson("/api/service-catalog/{$row->id}", ['name' => 'Hijacked'])
            ->assertStatus(404);

        // Row must remain untouched.
        $this->assertDatabaseHas('service_catalog_items', ['id' => $row->id, 'name' => 'Not yours']);
    }

    public function test_destroy_removes_owned_item(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        $row = ServiceCatalogItem::query()->create([
            'company_id' => $company->id,
            'name' => 'Delete me',
            'is_active' => true,
            'created_by_user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/service-catalog/{$row->id}")->assertOk();
        $this->assertDatabaseMissing('service_catalog_items', ['id' => $row->id]);
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Phase 7.12 '.str()->uuid(),
            'type' => 'operator',
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Phase 7.12 Test',
            'email' => 'p712-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function makeUserForCompany(Company $company): User
    {
        $user = $this->makeUser();
        $role = Role::query()->firstOrCreate(['name' => 'company_admin']);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }
}

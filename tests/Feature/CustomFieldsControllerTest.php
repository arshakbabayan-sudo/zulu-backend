<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomFieldDefinition;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7.4 feature tests — operator custom-field schema editor.
 *
 * Routes:
 *   GET    /api/custom-fields
 *   POST   /api/custom-fields
 *   PATCH  /api/custom-fields/{id}
 *   DELETE /api/custom-fields/{id}
 *
 * Validation surface includes:
 *   - `key` must be snake_case (regex /^[a-z0-9_]+$/)
 *   - `scope` ∈ {'all','hotel','flight',...}
 *   - `field_type` ∈ {'text','number','boolean','select','multi_select','date'}
 */
class CustomFieldsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_is_rejected(): void
    {
        $this->getJson('/api/custom-fields')->assertStatus(401);
    }

    public function test_index_returns_canonical_lists_plus_company_rows(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        CustomFieldDefinition::query()->create([
            'company_id' => $company->id,
            'scope' => 'hotel',
            'key' => 'inspector_signature',
            'label' => 'Inspector signature',
            'field_type' => 'text',
            'is_required' => false,
            'show_in_filter' => false,
            'display_order' => 0,
            'is_active' => true,
            'created_by_user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/custom-fields');
        $response->assertOk();
        $response->assertJsonPath(
            'data.available_field_types',
            CustomFieldDefinition::FIELD_TYPES
        );
        $response->assertJsonPath(
            'data.available_scopes',
            CustomFieldDefinition::SCOPES
        );
        $this->assertCount(1, $response->json('data.fields'));
        $this->assertSame('inspector_signature', $response->json('data.fields.0.key'));
    }

    public function test_store_creates_field_for_user_company(): void
    {
        $company = $this->makeCompany();
        Sanctum::actingAs($this->makeUserForCompany($company));

        $response = $this->postJson('/api/custom-fields', [
            'scope' => 'flight',
            'key' => 'meal_preference',
            'label' => 'Meal preference',
            'field_type' => 'select',
            'options' => ['vegan', 'kosher', 'halal'],
            'is_required' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.key', 'meal_preference');
        $response->assertJsonPath('data.is_required', true);
        $response->assertJsonPath('data.options.0', 'vegan');

        $this->assertDatabaseHas('custom_field_definitions', [
            'company_id' => $company->id,
            'key' => 'meal_preference',
            'field_type' => 'select',
        ]);
    }

    public function test_store_rejects_invalid_key_format(): void
    {
        $company = $this->makeCompany();
        Sanctum::actingAs($this->makeUserForCompany($company));

        $this->postJson('/api/custom-fields', [
            'scope' => 'all',
            'key' => 'Has-Hyphens',
            'label' => 'Bad key',
            'field_type' => 'text',
        ])->assertStatus(422)->assertJsonValidationErrors(['key']);
    }

    public function test_store_rejects_unknown_scope_and_field_type(): void
    {
        $company = $this->makeCompany();
        Sanctum::actingAs($this->makeUserForCompany($company));

        $this->postJson('/api/custom-fields', [
            'scope' => 'spaceship',
            'key' => 'good_key',
            'label' => 'Bad scope',
            'field_type' => 'text',
        ])->assertStatus(422)->assertJsonValidationErrors(['scope']);

        $this->postJson('/api/custom-fields', [
            'scope' => 'all',
            'key' => 'good_key',
            'label' => 'Bad field type',
            'field_type' => 'rocket',
        ])->assertStatus(422)->assertJsonValidationErrors(['field_type']);
    }

    public function test_store_requires_user_company(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/custom-fields', [
            'scope' => 'all',
            'key' => 'orphan_field',
            'label' => 'Orphan',
            'field_type' => 'text',
        ])->assertStatus(422)->assertJsonPath('message', 'No active company.');
    }

    public function test_update_modifies_owned_field(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        $row = CustomFieldDefinition::query()->create([
            'company_id' => $company->id,
            'scope' => 'all',
            'key' => 'rename_me',
            'label' => 'Original',
            'field_type' => 'text',
            'is_required' => false,
            'show_in_filter' => false,
            'display_order' => 0,
            'is_active' => true,
            'created_by_user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/custom-fields/{$row->id}", [
            'label' => 'Renamed',
            'is_required' => true,
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.label', 'Renamed');
        $response->assertJsonPath('data.is_required', true);
    }

    public function test_update_cross_company_returns_404(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $userB = $this->makeUserForCompany($companyB);
        $row = CustomFieldDefinition::query()->create([
            'company_id' => $companyA->id,
            'scope' => 'all',
            'key' => 'cross_company_target',
            'label' => 'Not yours',
            'field_type' => 'text',
            'is_required' => false,
            'show_in_filter' => false,
            'display_order' => 0,
            'is_active' => true,
            'created_by_user_id' => $this->makeUser()->id,
        ]);

        Sanctum::actingAs($userB);

        $this->patchJson("/api/custom-fields/{$row->id}", ['label' => 'Hijacked'])
            ->assertStatus(404);
    }

    public function test_destroy_removes_owned_field(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        $row = CustomFieldDefinition::query()->create([
            'company_id' => $company->id,
            'scope' => 'all',
            'key' => 'goodbye',
            'label' => 'Delete me',
            'field_type' => 'text',
            'is_required' => false,
            'show_in_filter' => false,
            'display_order' => 0,
            'is_active' => true,
            'created_by_user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/custom-fields/{$row->id}")->assertOk();
        $this->assertDatabaseMissing('custom_field_definitions', ['id' => $row->id]);
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Phase 7.4 '.str()->uuid(),
            'type' => 'operator',
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Phase 7.4 Test',
            'email' => 'p74-'.str()->uuid().'@example.test',
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

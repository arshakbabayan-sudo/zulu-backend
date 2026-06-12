<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Offer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap §4 — custom field VALUE storage on inventory entities.
 */
class CustomFieldValuesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function resetAuthGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function makeHotelOffer(Company $company, string $title = 'Stay'): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => $title,
            'price' => 100,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validHotelPayload(int $offerId): array
    {
        return [
            'offer_id' => $offerId,
            'location_id' => $this->locationIds()['yerevan_city'],
            'hotel_name' => 'CF Hotel',
            'property_type' => 'hotel',
            'hotel_type' => 'resort',
            'country' => 'AM',
            'city' => 'Yerevan',
            'meal_type' => 'bed_and_breakfast',
            'status' => 'draft',
            'availability_status' => 'available',
        ];
    }

    private function createHotelCrudUser(Company $company, string $email = 'cf-crud@tdd.local'): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'tdd_cf_hotel_crud_'.$company->id]);
        $ids = Permission::query()->whereIn('name', [
            'hotels.view', 'hotels.create', 'hotels.update', 'hotels.delete',
        ])->pluck('id')->all();
        $role->permissions()->sync($ids);

        $user = User::query()->create([
            'name' => 'CF crud user',
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);

        UserCompany::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    private function makeDefinition(Company $company, array $overrides = []): CustomFieldDefinition
    {
        return CustomFieldDefinition::query()->create(array_merge([
            'company_id' => $company->id,
            'scope' => 'hotel',
            'key' => 'wifi_note',
            'label' => 'WiFi note',
            'field_type' => 'text',
            'is_active' => true,
        ], $overrides));
    }

    public function test_create_hotel_stores_typed_values_and_values_endpoint_returns_them(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createHotelCrudUser($company);

        $this->makeDefinition($company, ['key' => 'note', 'field_type' => 'text']);
        $this->makeDefinition($company, ['key' => 'floors', 'field_type' => 'number']);
        $this->makeDefinition($company, ['key' => 'has_spa', 'field_type' => 'boolean']);
        $this->makeDefinition($company, ['key' => 'season', 'field_type' => 'select', 'options' => ['summer', 'winter']]);
        $this->makeDefinition($company, ['key' => 'tags', 'field_type' => 'multi_select', 'options' => ['family', 'business', 'eco']]);
        $this->makeDefinition($company, ['key' => 'renovated_on', 'field_type' => 'date']);
        // Scope 'all' applies to every vertical, including hotels.
        $this->makeDefinition($company, ['key' => 'internal_code', 'scope' => 'all']);
        // Inactive definitions are invisible to writes.
        $this->makeDefinition($company, ['key' => 'retired_field', 'is_active' => false]);

        $payload = $this->validHotelPayload($this->makeHotelOffer($company)->id) + [
            'custom_fields' => [
                'note' => 'Lobby only',
                'floors' => 12,
                'has_spa' => true,
                'season' => 'summer',
                'tags' => ['family', 'eco'],
                'renovated_on' => '2025-10-01',
                'internal_code' => 'HX-9',
            ],
        ];

        $hotelId = $this->postJson('/api/hotels', $payload, $this->authHeaders($user))
            ->assertStatus(201)
            ->json('data.id');

        $this->assertSame(7, CustomFieldValue::query()->where('entity_type', 'hotel')->where('entity_id', $hotelId)->count());

        $this->resetAuthGuards();

        $this->getJson('/api/custom-field-values?entity_type=hotel&entity_id='.$hotelId, $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.values.note', 'Lobby only')
            ->assertJsonPath('data.values.floors', 12)
            ->assertJsonPath('data.values.has_spa', true)
            ->assertJsonPath('data.values.season', 'summer')
            ->assertJsonPath('data.values.tags', ['family', 'eco'])
            ->assertJsonPath('data.values.renovated_on', '2025-10-01')
            ->assertJsonPath('data.values.internal_code', 'HX-9');
    }

    public function test_required_field_blocks_create_without_creating_entity(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createHotelCrudUser($company);
        $this->makeDefinition($company, ['key' => 'license_no', 'is_required' => true]);

        $before = \App\Models\Hotel::query()->count();

        $this->postJson('/api/hotels', $this->validHotelPayload($this->makeHotelOffer($company)->id), $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['custom_fields.license_no']);

        $this->assertSame($before, \App\Models\Hotel::query()->count());
    }

    public function test_invalid_values_rejected_with_per_key_errors(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createHotelCrudUser($company);
        $this->makeDefinition($company, ['key' => 'floors', 'field_type' => 'number']);
        $this->makeDefinition($company, ['key' => 'season', 'field_type' => 'select', 'options' => ['summer', 'winter']]);
        $this->makeDefinition($company, ['key' => 'renovated_on', 'field_type' => 'date']);

        $payload = $this->validHotelPayload($this->makeHotelOffer($company)->id) + [
            'custom_fields' => [
                'floors' => 'not-a-number',
                'season' => 'spring',
                'renovated_on' => '01/10/2025',
                'ghost_key' => 'x',
            ],
        ];

        $this->postJson('/api/hotels', $payload, $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'custom_fields.floors',
                'custom_fields.season',
                'custom_fields.renovated_on',
                'custom_fields.ghost_key',
            ]);
    }

    public function test_update_merges_partially_and_null_clears_value(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createHotelCrudUser($company);
        $this->makeDefinition($company, ['key' => 'note']);
        $this->makeDefinition($company, ['key' => 'floors', 'field_type' => 'number']);

        $hotelId = $this->postJson(
            '/api/hotels',
            $this->validHotelPayload($this->makeHotelOffer($company)->id) + [
                'custom_fields' => ['note' => 'old note', 'floors' => 4],
            ],
            $this->authHeaders($user)
        )->assertStatus(201)->json('data.id');

        $this->resetAuthGuards();

        // Partial update: only `note` touched, `floors` untouched; an update
        // WITHOUT custom_fields at all leaves everything in place.
        $this->patchJson('/api/hotels/'.$hotelId, ['custom_fields' => ['note' => 'new note']], $this->authHeaders($user))
            ->assertOk();

        $this->resetAuthGuards();
        $this->patchJson('/api/hotels/'.$hotelId, ['city' => 'Gyumri'], $this->authHeaders($user))->assertOk();

        $this->resetAuthGuards();
        $this->getJson('/api/custom-field-values?entity_type=hotel&entity_id='.$hotelId, $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.values.note', 'new note')
            ->assertJsonPath('data.values.floors', 4);

        // Null clears the stored row.
        $this->resetAuthGuards();
        $this->patchJson('/api/hotels/'.$hotelId, ['custom_fields' => ['floors' => null]], $this->authHeaders($user))
            ->assertOk();

        $this->assertSame(1, CustomFieldValue::query()->where('entity_type', 'hotel')->where('entity_id', $hotelId)->count());
    }

    public function test_foreign_company_definitions_do_not_apply_and_values_are_isolated(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $companyA = Company::query()->firstOrFail();
        $companyB = Company::query()->create(['name' => 'Other Co', 'type' => 'operator']);
        $userA = $this->createHotelCrudUser($companyA, 'cf-a@tdd.local');
        $userB = $this->createHotelCrudUser($companyB, 'cf-b@tdd.local');

        // Definition belongs to company B only.
        $this->makeDefinition($companyB, ['key' => 'b_only_field']);

        // Company A's user cannot write against B's definition (unknown key for A).
        $this->postJson(
            '/api/hotels',
            $this->validHotelPayload($this->makeHotelOffer($companyA)->id) + [
                'custom_fields' => ['b_only_field' => 'x'],
            ],
            $this->authHeaders($userA)
        )->assertStatus(422)->assertJsonValidationErrors(['custom_fields.b_only_field']);

        // A hotel of company A is invisible to company B's values endpoint.
        $this->resetAuthGuards();
        $hotelId = $this->postJson(
            '/api/hotels',
            $this->validHotelPayload($this->makeHotelOffer($companyA, 'A2')->id),
            $this->authHeaders($userA)
        )->assertStatus(201)->json('data.id');

        $this->resetAuthGuards();
        $this->getJson('/api/custom-field-values?entity_type=hotel&entity_id='.$hotelId, $this->authHeaders($userB))
            ->assertStatus(403);
    }

    public function test_definition_delete_cascades_and_hotel_delete_purges_values(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createHotelCrudUser($company);
        $definition = $this->makeDefinition($company, ['key' => 'note']);
        $keeper = $this->makeDefinition($company, ['key' => 'floors', 'field_type' => 'number']);

        $hotelId = $this->postJson(
            '/api/hotels',
            $this->validHotelPayload($this->makeHotelOffer($company)->id) + [
                'custom_fields' => ['note' => 'bye', 'floors' => 2],
            ],
            $this->authHeaders($user)
        )->assertStatus(201)->json('data.id');

        // Deleting the definition cascades its values away.
        $definition->delete();
        $this->assertSame(1, CustomFieldValue::query()->where('entity_type', 'hotel')->where('entity_id', $hotelId)->count());

        // Deleting the entity purges the rest (HasCustomFieldValues trait).
        $this->resetAuthGuards();
        $this->deleteJson('/api/hotels/'.$hotelId, [], $this->authHeaders($user))->assertOk();
        $this->assertSame(0, CustomFieldValue::query()->where('entity_type', 'hotel')->where('entity_id', $hotelId)->count());
        $this->assertNotNull($keeper->fresh());
    }

    public function test_package_vertical_round_trip_with_all_scope_definition(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $this->makeDefinition($company, ['key' => 'internal_code', 'scope' => 'all']);

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'package',
            'title' => 'Pack',
            'price' => 500,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $packageId = $this->postJson('/api/packages', [
            'offer_id' => $offer->id,
            'package_type' => 'fixed',
            'custom_fields' => ['internal_code' => 'PKG-7'],
        ], $this->authHeaders($admin))->assertStatus(201)->json('data.id');

        $this->resetAuthGuards();

        $this->getJson('/api/custom-field-values?entity_type=package&entity_id='.$packageId, $this->authHeaders($admin))
            ->assertOk()
            ->assertJsonPath('data.values.internal_code', 'PKG-7');
    }
}

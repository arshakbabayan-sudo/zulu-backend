<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Excursion;
use App\Models\Offer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExcursionWriteApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function makeExcursionOffer(Company $company, string $title = 'City walk', float $price = 25): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'excursion',
            'title' => $title,
            'price' => $price,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validCreatePayload(int $offerId, int $companyId, array $overrides = []): array
    {
        return array_merge([
            'offer_id' => $offerId,
            'company_id' => $companyId,
            'location' => 'Republic Square',
            'duration' => '3 hours',
            'group_size' => 12,
            'country' => 'AM',
            'city' => 'Yerevan',
            'general_category' => 'culture',
            'category' => 'walking',
            'excursion_type' => 'guided',
            'tour_name' => 'Old city',
            'overview' => 'Historic center walk.',
            'starts_at' => '2026-06-01T10:00:00Z',
            'ends_at' => '2026-06-01T13:00:00Z',
            'language' => 'en',
            'ticket_max_count' => 20,
            'status' => 'draft',
            'is_available' => true,
            'is_bookable' => true,
            'includes' => ['Guide', 'Transport'],
            'meeting_pickup' => 'Hotel lobby',
            'additional_info' => 'Bring water.',
            'cancellation_policy' => 'Free cancel 24h before.',
            'photos' => ['https://example.com/a.jpg'],
            'price_by_dates' => [
                ['date' => '2026-07-01', 'price' => 49.99],
            ],
        ], $overrides);
    }

    private function createExcursionCrudUser(Company $company): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'tdd_excursion_scoped_crud']);
        $ids = Permission::query()->whereIn('name', [
            'excursions.view',
            'excursions.create',
            'excursions.update',
            'excursions.delete',
        ])->pluck('id')->all();
        $role->permissions()->sync($ids);

        $user = User::query()->create([
            'name' => 'Excursion CRUD',
            'email' => 'excursion-crud@tdd.local',
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

    public function test_store_creates_excursion_with_expanded_fields_and_stable_envelope(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createExcursionCrudUser($company);
        $offer = $this->makeExcursionOffer($company);

        $res = $this->postJson(
            '/api/excursions',
            $this->validCreatePayload($offer->id, $company->id),
            $this->authHeaders($user)
        );

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.offer.type', 'excursion')
            ->assertJsonPath('data.title', 'City walk')
            ->assertJsonPath('data.price', 49.99)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.country', 'AM')
            ->assertJsonPath('data.city', 'Yerevan')
            ->assertJsonPath('data.excursion_type', 'guided')
            ->assertJsonPath('data.ticket_max_count', 20)
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.includes', ['Guide', 'Transport'])
            ->assertJsonPath('data.meeting_pickup', 'Hotel lobby')
            ->assertJsonPath('data.price_by_dates.0.date', '2026-07-01')
            ->assertJsonPath('data.price_by_dates.0.price', 49.99);

        $this->assertDatabaseHas('excursions', [
            'offer_id' => $offer->id,
            'country' => 'AM',
            'city' => 'Yerevan',
            'excursion_type' => 'guided',
        ]);
    }

    public function test_store_rejects_non_excursion_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createExcursionCrudUser($company);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => 'Stay',
            'price' => 10,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $this->postJson(
            '/api/excursions',
            $this->validCreatePayload($offer->id, $company->id),
            $this->authHeaders($user)
        )->assertStatus(422)->assertJsonValidationErrors(['offer_id']);
    }

    public function test_store_rejects_inverted_schedule_window(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createExcursionCrudUser($company);
        $offer = $this->makeExcursionOffer($company);

        $this->postJson(
            '/api/excursions',
            $this->validCreatePayload($offer->id, $company->id, [
                'starts_at' => '2026-06-01T13:00:00Z',
                'ends_at' => '2026-06-01T10:00:00Z',
            ]),
            $this->authHeaders($user)
        )->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
    }

    public function test_store_rejects_missing_required_core_fields(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createExcursionCrudUser($company);
        $offer = $this->makeExcursionOffer($company);

        $this->postJson(
            '/api/excursions',
            [
                'offer_id' => $offer->id,
                'company_id' => $company->id,
                'location' => 'X',
                'duration' => '1h',
                // group_size missing
            ],
            $this->authHeaders($user)
        )->assertStatus(422)->assertJsonValidationErrors(['group_size']);
    }

    public function test_index_filters_by_core_fields(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createExcursionCrudUser($company);
        $headers = $this->authHeaders($user);

        $o1 = $this->makeExcursionOffer($company, 'Tour A');
        $o2 = $this->makeExcursionOffer($company, 'Tour B');

        $this->postJson('/api/excursions', $this->validCreatePayload($o1->id, $company->id, [
            'country' => 'AM',
            'city' => 'Yerevan',
            'excursion_type' => 'guided',
            'status' => 'published',
        ]), $headers)->assertStatus(201);

        $this->postJson('/api/excursions', $this->validCreatePayload($o2->id, $company->id, [
            'country' => 'GE',
            'city' => 'Tbilisi',
            'excursion_type' => 'self_guided',
            'status' => 'draft',
        ]), $headers)->assertStatus(201);

        $json = $this->getJson('/api/excursions?country=AM&excursion_type=guided', $headers)->json('data');
        $this->assertCount(1, $json);
        $this->assertSame('AM', $json[0]['country']);
        $this->assertSame('guided', $json[0]['excursion_type']);

        $json2 = $this->getJson('/api/excursions?city=Tbilisi&status=draft', $headers)->json('data');
        $this->assertCount(1, $json2);
        $this->assertSame('GE', $json2[0]['country']);
    }

    public function test_update_partial_expanded_fields(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createExcursionCrudUser($company);
        $headers = $this->authHeaders($user);
        $offer = $this->makeExcursionOffer($company);

        $id = (int) $this->postJson(
            '/api/excursions',
            $this->validCreatePayload($offer->id, $company->id),
            $headers
        )->json('data.id');

        $this->patchJson('/api/excursions/'.$id, [
            'city' => 'Gyumri',
            'language' => 'hy',
            'ticket_max_count' => 15,
            'status' => 'published',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.city', 'Gyumri')
            ->assertJsonPath('data.language', 'hy')
            ->assertJsonPath('data.ticket_max_count', 15)
            ->assertJsonPath('data.status', 'published');

        $this->assertSame('published', Excursion::query()->findOrFail($id)->status);
    }

    public function test_update_rejects_inverted_schedule_when_merged_with_existing(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createExcursionCrudUser($company);
        $headers = $this->authHeaders($user);
        $offer = $this->makeExcursionOffer($company);

        $id = (int) $this->postJson(
            '/api/excursions',
            $this->validCreatePayload($offer->id, $company->id, [
                'starts_at' => '2026-06-01T10:00:00Z',
                'ends_at' => '2026-06-01T13:00:00Z',
            ]),
            $headers
        )->json('data.id');

        $this->patchJson('/api/excursions/'.$id, [
            'ends_at' => '2026-06-01T09:00:00Z',
        ], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ends_at']);
    }

    public function test_store_and_update_persist_step_c3_visibility_fields(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createExcursionCrudUser($company);
        $headers = $this->authHeaders($user);
        $offer = $this->makeExcursionOffer($company);

        $res = $this->postJson('/api/excursions', $this->validCreatePayload($offer->id, $company->id, [
            'visibility_rule' => 'show_accepted_only',
            'appears_in_web' => false,
            'appears_in_admin' => true,
            'appears_in_zulu_admin' => false,
        ]), $headers);

        $res->assertStatus(201)
            ->assertJsonPath('data.visibility_rule', 'show_accepted_only')
            ->assertJsonPath('data.appears_in_web', false)
            ->assertJsonPath('data.appears_in_admin', true)
            ->assertJsonPath('data.appears_in_zulu_admin', false);

        $id = (int) $res->json('data.id');

        $this->patchJson('/api/excursions/'.$id, [
            'visibility_rule' => 'show_all',
            'appears_in_web' => true,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.visibility_rule', 'show_all')
            ->assertJsonPath('data.appears_in_web', true)
            ->assertJsonPath('data.appears_in_zulu_admin', false);

        $row = Excursion::query()->findOrFail($id);
        $this->assertSame('show_all', $row->visibility_rule);
        $this->assertTrue($row->appears_in_web);
        $this->assertTrue($row->appears_in_admin);
        $this->assertFalse($row->appears_in_zulu_admin);
    }
}

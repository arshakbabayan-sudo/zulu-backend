<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\HotelRoomPricing;
use App\Models\Offer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use App\Services\Admin\AdminAccessService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotelWriteApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function makeHotelOffer(Company $company, string $title = 'Stay', float $price = 100): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => $title,
            'price' => $price,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validCreatePayload(int $offerId): array
    {
        return [
            'offer_id' => $offerId,
            'hotel_name' => 'API Hotel',
            'property_type' => 'hotel',
            'hotel_type' => 'resort',
            'country' => 'AM',
            'city' => 'Yerevan',
            'meal_type' => 'bed_and_breakfast',
            'status' => 'draft',
            'availability_status' => 'available',
            'rooms' => [
                [
                    'room_type' => 'double',
                    'room_name' => 'Deluxe',
                    'max_adults' => 2,
                    'max_children' => 0,
                    'max_total_guests' => 2,
                    'pricings' => [
                        ['price' => 150, 'currency' => 'USD'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Seeded `agent` role: .view permissions only (includes hotels.view, excludes hotels.create/update/delete).
     */
    private function createAgentLinkedUser(Company $company): User
    {
        $agentRole = Role::query()->where('name', 'agent')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Agent user',
            'email' => 'tdd-agent-hotel@local.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);

        UserCompany::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $agentRole->id,
        ]);

        return $user;
    }

    private function createScopedHotelCrudUser(Company $company): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'tdd_hotel_scoped_crud']);
        $ids = Permission::query()->whereIn('name', [
            'hotels.view',
            'hotels.create',
            'hotels.update',
            'hotels.delete',
        ])->pluck('id')->all();
        $role->permissions()->sync($ids);

        $user = User::query()->create([
            'name' => 'Scoped CRUD',
            'email' => 'hotel-scoped-crud@tdd.local',
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

    /**
     * Sanctum's RequestGuard caches the resolved user across kernel handles in one PHP process.
     */
    private function resetAuthGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_store_update_destroy_require_authentication(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $this->postJson('/api/hotels', [])->assertUnauthorized();
        $this->patchJson('/api/hotels/1', [])->assertUnauthorized();
        $this->deleteJson('/api/hotels/1')->assertUnauthorized();
    }

    public function test_store_update_destroy_require_permission(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $agentUser = $this->createAgentLinkedUser($company);
        $agentHeaders = $this->authHeaders($agentUser);
        $adminHeaders = $this->authHeaders($admin);

        $hotel = $this->postJson(
            '/api/hotels',
            $this->validCreatePayload($this->makeHotelOffer($company, 'H2')->id),
            $adminHeaders
        )->assertStatus(201)->json('data.id');

        $this->resetAuthGuards();

        $this->patchJson('/api/hotels/'.$hotel, ['city' => 'Gyumri'], $agentHeaders)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden');

        $this->resetAuthGuards();

        $this->deleteJson('/api/hotels/'.$hotel, [], $agentHeaders)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden');
    }

    public function test_agent_user_cannot_create_hotel(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $agentUser = $this->createAgentLinkedUser($company);
        $offer = $this->makeHotelOffer($company);

        $this->postJson('/api/hotels', $this->validCreatePayload($offer->id), $this->authHeaders($agentUser))
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden');
    }

    public function test_store_creates_hotel_and_returns_detail(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company, 'OK', 77);

        $res = $this->postJson(
            '/api/hotels',
            $this->validCreatePayload($offer->id),
            $this->authHeaders($admin)
        );

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.offer.type', 'hotel');
        $this->assertEqualsWithDelta(150.0, (float) $res->json('data.rooms.0.pricings.0.price'), 0.01);

        $offer->refresh();
        $this->assertEqualsWithDelta(77.0, (float) $offer->price, 0.01);
    }

    public function test_store_rejects_non_hotel_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Fly',
            'price' => 10,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $this->postJson(
            '/api/hotels',
            $this->validCreatePayload($offer->id),
            $this->authHeaders($admin)
        )->assertStatus(422)->assertJsonValidationErrors(['offer_id']);
    }

    public function test_store_rejects_duplicate_hotel_per_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);
        $headers = $this->authHeaders($admin);
        $payload = $this->validCreatePayload($offer->id);

        $this->postJson('/api/hotels', $payload, $headers)->assertStatus(201);
        $this->postJson('/api/hotels', $payload, $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offer_id']);
    }

    public function test_store_rejects_company_id_in_body(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);
        $payload = $this->validCreatePayload($offer->id);
        $payload['company_id'] = $company->id;

        $this->postJson('/api/hotels', $payload, $this->authHeaders($admin))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_id']);
    }

    public function test_store_inherits_company_id_from_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);

        $id = (int) $this->postJson(
            '/api/hotels',
            $this->validCreatePayload($offer->id),
            $this->authHeaders($admin)
        )->json('data.id');

        $hotel = Hotel::query()->findOrFail($id);
        $this->assertSame($company->id, $hotel->company_id);
        $this->assertSame($offer->id, $hotel->offer_id);
    }

    public function test_update_scalar_fields_and_rejects_locked_fields(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);
        $headers = $this->authHeaders($admin);
        $hotelId = (int) $this->postJson('/api/hotels', $this->validCreatePayload($offer->id), $headers)
            ->json('data.id');

        $this->patchJson('/api/hotels/'.$hotelId, ['city' => 'Gyumri', 'hotel_name' => 'Renamed'], $headers)
            ->assertOk()
            ->assertJsonPath('data.city', 'Gyumri')
            ->assertJsonPath('data.hotel_name', 'Renamed');

        $this->patchJson('/api/hotels/'.$hotelId, ['offer_id' => 999], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offer_id']);

        $this->patchJson('/api/hotels/'.$hotelId, ['company_id' => 999], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_id']);

        $this->patchJson('/api/hotels/'.$hotelId, ['rooms' => []], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rooms']);
    }

    public function test_destroy_removes_hotel_and_cascades_children(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);
        $headers = $this->authHeaders($admin);
        $data = $this->postJson('/api/hotels', $this->validCreatePayload($offer->id), $headers)
            ->assertStatus(201)
            ->json('data');
        $hotelId = (int) $data['id'];
        $roomId = (int) $data['rooms'][0]['id'];
        $pricingId = (int) $data['rooms'][0]['pricings'][0]['id'];

        $this->deleteJson('/api/hotels/'.$hotelId, [], $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('hotels', ['id' => $hotelId]);
        $this->assertDatabaseMissing('hotel_rooms', ['id' => $roomId]);
        $this->assertDatabaseMissing('hotel_room_pricings', ['id' => $pricingId]);
    }

    public function test_update_and_delete_return_404_out_of_scope(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $c1 = Company::query()->firstOrFail();
        $c2 = Company::query()->create([
            'name' => 'Other tenant',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $this->assertNotSame($c1->id, $c2->id);

        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $createRes = $this->postJson(
            '/api/hotels',
            $this->validCreatePayload($this->makeHotelOffer($c2)->id),
            $this->authHeaders($admin)
        )->assertStatus(201);
        $this->assertSame($c2->id, (int) $createRes->json('data.company_id'));
        $hotelId = (int) $createRes->json('data.id');

        $this->resetAuthGuards();

        $scoped = $this->createScopedHotelCrudUser($c1);
        $this->assertFalse(app(AdminAccessService::class)->isSuperAdmin($scoped));
        $this->assertSame(
            [(int) $c1->id],
            app(AdminAccessService::class)->companyIdsForCommerceList($scoped, 'hotels.update')
        );
        $headers = $this->authHeaders($scoped);

        $this->patchJson('/api/hotels/'.$hotelId, ['city' => 'X'], $headers)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Not found');

        $this->deleteJson('/api/hotels/'.$hotelId, [], $headers)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Not found');
    }
}

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
            'location_id' => $this->locationIds()['yerevan_city'],
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
     * Tenant user with hotels.view only (no platform.* permissions).
     * Seeded `agent` also receives platform.*.view perms, which classify as platform admin for commerce APIs.
     */
    private function createAgentLinkedUser(Company $company): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'tdd_hotel_view_only']);
        $viewId = Permission::query()->where('name', 'hotels.view')->value('id');
        $role->permissions()->sync(array_filter([$viewId]));

        $user = User::query()->create([
            'name' => 'Agent user',
            'email' => 'tdd-agent-hotel@local.test',
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
        $this->assertEqualsWithDelta(150.0, (float) $offer->price, 0.01);
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

        $this->patchJson('/api/hotels/'.$hotelId, [
            'location_id' => $this->locationIds()['gyumri_city'],
            'hotel_name' => 'Renamed',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.location_id', $this->locationIds()['gyumri_city'])
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

    public function test_update_can_sync_rooms(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);
        $headers = $this->authHeaders($admin);
        $hotelId = (int) $this->postJson('/api/hotels', $this->validCreatePayload($offer->id), $headers)
            ->json('data.id');

        $res = $this->patchJson('/api/hotels/'.$hotelId, [
            'rooms' => [
                [
                    'room_type' => 'suite',
                    'room_name' => 'Presidential',
                    'max_adults' => 4,
                    'max_children' => 0,
                    'max_total_guests' => 4,
                    'pricings' => [
                        [
                            'price' => 400,
                            'currency' => 'EUR',
                            'pricing_mode' => 'per_night',
                            'valid_from' => '2026-06-01',
                            'valid_to' => '2026-08-31',
                            'min_nights' => 2,
                            'status' => 'active',
                        ],
                    ],
                ],
            ],
        ], $headers);

        $res->assertOk()
            ->assertJsonPath('data.rooms.0.room_type', 'suite')
            ->assertJsonPath('data.rooms.0.pricings.0.currency', 'EUR');
        $this->assertEqualsWithDelta(400.0, (float) $res->json('data.rooms.0.pricings.0.price'), 0.01);
        $this->assertCount(1, HotelRoom::query()->where('hotel_id', $hotelId)->get());
    }

    public function test_update_rooms_upsert_preserves_ids_when_ids_sent(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);
        $headers = $this->authHeaders($admin);
        $create = $this->postJson('/api/hotels', $this->validCreatePayload($offer->id), $headers)
            ->assertStatus(201)
            ->json('data');
        $hotelId = (int) $create['id'];
        $roomId = (int) $create['rooms'][0]['id'];
        $pricingId = (int) $create['rooms'][0]['pricings'][0]['id'];

        $patch = $this->patchJson('/api/hotels/'.$hotelId, [
            'rooms' => [
                [
                    'id' => $roomId,
                    'room_type' => 'double',
                    'room_name' => 'Deluxe Renamed',
                    'max_adults' => 2,
                    'max_children' => 0,
                    'max_total_guests' => 2,
                    'pricings' => [
                        [
                            'id' => $pricingId,
                            'price' => 175,
                            'currency' => 'USD',
                            'pricing_mode' => 'per_night',
                            'status' => 'active',
                        ],
                    ],
                ],
            ],
        ], $headers);

        $patch->assertOk()
            ->assertJsonPath('data.rooms.0.id', $roomId)
            ->assertJsonPath('data.rooms.0.room_name', 'Deluxe Renamed')
            ->assertJsonPath('data.rooms.0.pricings.0.id', $pricingId);
        $this->assertEqualsWithDelta(175.0, (float) $patch->json('data.rooms.0.pricings.0.price'), 0.01);
        $this->assertSame(1, HotelRoom::query()->where('hotel_id', $hotelId)->count());
        $this->assertDatabaseHas('hotel_room_pricings', [
            'id' => $pricingId,
            'hotel_room_id' => $roomId,
            'price' => '175.00',
        ]);
    }

    public function test_update_rooms_deletes_omitted_room_and_pricing_rows(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);
        $headers = $this->authHeaders($admin);
        $hotelId = (int) $this->postJson('/api/hotels', [
            'offer_id' => $offer->id,
            'location_id' => $this->locationIds()['yerevan_city'],
            'hotel_name' => 'Two Room Hotel',
            'property_type' => 'hotel',
            'hotel_type' => 'resort',
            'country' => 'AM',
            'city' => 'Yerevan',
            'meal_type' => 'bed_and_breakfast',
            'status' => 'draft',
            'availability_status' => 'available',
            'rooms' => [
                [
                    'room_type' => 'single',
                    'room_name' => 'A',
                    'max_adults' => 1,
                    'max_children' => 0,
                    'max_total_guests' => 1,
                    'pricings' => [['price' => 50, 'currency' => 'USD']],
                ],
                [
                    'room_type' => 'double',
                    'room_name' => 'B',
                    'max_adults' => 2,
                    'max_children' => 0,
                    'max_total_guests' => 2,
                    'pricings' => [['price' => 90, 'currency' => 'USD']],
                ],
            ],
        ], $headers)->assertStatus(201)->json('data.id');

        $roomsBefore = HotelRoom::query()->where('hotel_id', $hotelId)->orderBy('id')->get();
        $this->assertCount(2, $roomsBefore);
        $keepRoom = $roomsBefore->first();
        $dropRoom = $roomsBefore->last();
        $keepPricingId = (int) $keepRoom->pricings()->firstOrFail()->id;

        $this->patchJson('/api/hotels/'.$hotelId, [
            'rooms' => [
                [
                    'id' => $keepRoom->id,
                    'room_type' => 'single',
                    'room_name' => 'A Updated',
                    'max_adults' => 1,
                    'max_children' => 0,
                    'max_total_guests' => 1,
                    'pricings' => [
                        [
                            'id' => $keepPricingId,
                            'price' => 55,
                            'currency' => 'USD',
                            'pricing_mode' => 'per_night',
                            'status' => 'active',
                        ],
                        ['price' => 60, 'currency' => 'USD'],
                    ],
                ],
            ],
        ], $headers)->assertOk();

        $this->assertDatabaseMissing('hotel_rooms', ['id' => $dropRoom->id]);
        $this->assertDatabaseHas('hotel_rooms', ['id' => $keepRoom->id, 'room_name' => 'A Updated']);
        $this->assertSame(2, $keepRoom->fresh()->pricings()->count());
    }

    public function test_update_rejects_room_id_from_another_hotel(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $headers = $this->authHeaders($admin);

        $hotelA = (int) $this->postJson(
            '/api/hotels',
            $this->validCreatePayload($this->makeHotelOffer($company, 'HA')->id),
            $headers
        )->json('data.id');
        $foreignRoomId = (int) HotelRoom::query()->where('hotel_id', $hotelA)->value('id');

        $hotelB = (int) $this->postJson(
            '/api/hotels',
            $this->validCreatePayload($this->makeHotelOffer($company, 'HB')->id),
            $headers
        )->json('data.id');

        $this->patchJson('/api/hotels/'.$hotelB, [
            'rooms' => [
                [
                    'id' => $foreignRoomId,
                    'room_type' => 'suite',
                    'room_name' => 'Stolen',
                    'max_adults' => 2,
                    'max_children' => 0,
                    'max_total_guests' => 2,
                    'pricings' => [
                        ['price' => 100, 'currency' => 'USD', 'pricing_mode' => 'per_night', 'status' => 'active'],
                    ],
                ],
            ],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors(['rooms.0.id']);
    }

    public function test_update_rejects_pricing_id_not_belonging_to_room(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $headers = $this->authHeaders($admin);

        $hotelId = (int) $this->postJson(
            '/api/hotels',
            [
                'offer_id' => $this->makeHotelOffer($company)->id,
                'location_id' => $this->locationIds()['yerevan_city'],
                'hotel_name' => 'H',
                'property_type' => 'hotel',
                'hotel_type' => 'resort',
                'country' => 'AM',
                'city' => 'Yerevan',
                'meal_type' => 'bed_and_breakfast',
                'status' => 'draft',
                'availability_status' => 'available',
                'rooms' => [
                    [
                        'room_type' => 'a',
                        'room_name' => 'R1',
                        'max_adults' => 1,
                        'max_children' => 0,
                        'max_total_guests' => 1,
                        'pricings' => [['price' => 10, 'currency' => 'USD']],
                    ],
                    [
                        'room_type' => 'b',
                        'room_name' => 'R2',
                        'max_adults' => 1,
                        'max_children' => 0,
                        'max_total_guests' => 1,
                        'pricings' => [['price' => 20, 'currency' => 'USD']],
                    ],
                ],
            ],
            $headers
        )->json('data.id');

        $rooms = HotelRoom::query()->where('hotel_id', $hotelId)->orderBy('id')->get();
        $room1 = $rooms->first();
        $room2 = $rooms->last();
        $pricingFromRoom2 = (int) $room2->pricings()->firstOrFail()->id;

        $this->patchJson('/api/hotels/'.$hotelId, [
            'rooms' => [
                [
                    'id' => $room1->id,
                    'room_type' => 'a',
                    'room_name' => 'R1',
                    'max_adults' => 1,
                    'max_children' => 0,
                    'max_total_guests' => 1,
                    'pricings' => [
                        [
                            'id' => $pricingFromRoom2,
                            'price' => 99,
                            'currency' => 'USD',
                            'pricing_mode' => 'per_night',
                            'status' => 'active',
                        ],
                    ],
                ],
                [
                    'id' => $room2->id,
                    'room_type' => 'b',
                    'room_name' => 'R2',
                    'max_adults' => 1,
                    'max_children' => 0,
                    'max_total_guests' => 1,
                    'pricings' => [
                        [
                            'id' => $room2->pricings()->firstOrFail()->id,
                            'price' => 20,
                            'currency' => 'USD',
                            'pricing_mode' => 'per_night',
                            'status' => 'active',
                        ],
                    ],
                ],
            ],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors(['rooms.0.pricings.0.id']);
    }

    public function test_update_rejects_pricing_id_on_new_room_row(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);
        $headers = $this->authHeaders($admin);
        $hotelId = (int) $this->postJson('/api/hotels', $this->validCreatePayload($offer->id), $headers)
            ->json('data.id');
        $existingPricingId = (int) HotelRoomPricing::query()
            ->whereHas('room', fn ($q) => $q->where('hotel_id', $hotelId))
            ->value('id');

        $this->patchJson('/api/hotels/'.$hotelId, [
            'rooms' => [
                [
                    'room_type' => 'new',
                    'room_name' => 'New row',
                    'max_adults' => 2,
                    'max_children' => 0,
                    'max_total_guests' => 2,
                    'pricings' => [
                        [
                            'id' => $existingPricingId,
                            'price' => 200,
                            'currency' => 'USD',
                            'pricing_mode' => 'per_night',
                            'status' => 'active',
                        ],
                    ],
                ],
            ],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors(['rooms.0.pricings.0.id']);
    }

    public function test_update_rejects_duplicate_room_ids_in_payload(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $headers = $this->authHeaders($admin);
        $hotelId = (int) $this->postJson(
            '/api/hotels',
            $this->validCreatePayload($this->makeHotelOffer($company)->id),
            $headers
        )->json('data.id');
        $roomId = (int) HotelRoom::query()->where('hotel_id', $hotelId)->value('id');
        $row = [
            'id' => $roomId,
            'room_type' => 'double',
            'room_name' => 'X',
            'max_adults' => 2,
            'max_children' => 0,
            'max_total_guests' => 2,
            'pricings' => [
                ['price' => 100, 'currency' => 'USD', 'pricing_mode' => 'per_night', 'status' => 'active'],
            ],
        ];

        $this->patchJson('/api/hotels/'.$hotelId, [
            'rooms' => [$row, $row],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors(['rooms.1.id']);
    }

    public function test_store_rejects_room_and_pricing_ids_in_payload(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);
        $headers = $this->authHeaders($admin);
        $payload = $this->validCreatePayload($offer->id);
        $payload['rooms'][0]['id'] = 99999;
        $payload['rooms'][0]['pricings'][0]['id'] = 88888;

        $this->postJson('/api/hotels', $payload, $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rooms.0.id', 'rooms.0.pricings.0.id']);
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

        $this->patchJson('/api/hotels/'.$hotelId, ['hotel_name' => 'X'], $headers)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Not found');

        $this->deleteJson('/api/hotels/'.$hotelId, [], $headers)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Not found');
    }
}

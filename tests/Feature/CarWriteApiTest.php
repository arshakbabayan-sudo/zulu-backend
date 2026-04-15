<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Company;
use App\Models\Offer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarWriteApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function makeCarOffer(Company $company, string $title = 'Rent me', float $price = 40): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'car',
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
            'location_id' => $this->locationIds()['yerevan_city'],
            'pickup_location' => 'EVN',
            'dropoff_location' => 'City',
            'vehicle_class' => 'economy',
            'vehicle_type' => 'sedan',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2022,
            'transmission_type' => 'automatic',
            'fuel_type' => 'petrol',
            'fleet' => 'standard',
            'category' => 'compact',
            'seats' => 5,
            'suitcases' => 2,
            'small_bag' => 1,
            'availability_window_start' => '2026-01-01T10:00:00Z',
            'availability_window_end' => '2026-12-31T18:00:00Z',
            'pricing_mode' => 'per_day',
            'base_price' => 35.5,
            'status' => 'draft',
            'availability_status' => 'available',
        ], $overrides);
    }

    private function createCarCrudUser(Company $company): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'tdd_car_scoped_crud']);
        $ids = Permission::query()->whereIn('name', [
            'cars.view',
            'cars.create',
            'cars.update',
            'cars.delete',
        ])->pluck('id')->all();
        $role->permissions()->sync($ids);

        $user = User::query()->create([
            'name' => 'Car CRUD',
            'email' => 'car-crud@tdd.local',
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

    public function test_store_creates_car_with_expanded_fields_and_stable_envelope(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $offer = $this->makeCarOffer($company);

        $res = $this->postJson(
            '/api/cars',
            $this->validCreatePayload($offer->id, $company->id),
            $this->authHeaders($user)
        );

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.offer.type', 'car')
            ->assertJsonPath('data.vehicle_type', 'sedan')
            ->assertJsonPath('data.brand', 'Toyota')
            ->assertJsonPath('data.pricing_mode', 'per_day')
            ->assertJsonPath('data.pricing.base_price', 35.5)
            ->assertJsonPath('data.availability.capacity', 5);

        $this->assertDatabaseHas('cars', [
            'offer_id' => $offer->id,
            'vehicle_type' => 'sedan',
            'pricing_mode' => 'per_day',
        ]);
    }

    public function test_store_rejects_non_car_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => 'Stay',
            'price' => 10,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $this->postJson(
            '/api/cars',
            $this->validCreatePayload($offer->id, $company->id),
            $this->authHeaders($user)
        )->assertStatus(422)->assertJsonValidationErrors(['offer_id']);
    }

    public function test_store_rejects_invalid_pricing_mode(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $offer = $this->makeCarOffer($company);

        $this->postJson(
            '/api/cars',
            $this->validCreatePayload($offer->id, $company->id, ['pricing_mode' => 'invalid_mode']),
            $this->authHeaders($user)
        )->assertStatus(422)->assertJsonValidationErrors(['pricing_mode']);
    }

    public function test_store_rejects_invalid_availability_status(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $offer = $this->makeCarOffer($company);

        $this->postJson(
            '/api/cars',
            $this->validCreatePayload($offer->id, $company->id, ['availability_status' => 'unknown']),
            $this->authHeaders($user)
        )->assertStatus(422)->assertJsonValidationErrors(['availability_status']);
    }

    public function test_store_rejects_inverted_availability_window(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $offer = $this->makeCarOffer($company);

        $this->postJson(
            '/api/cars',
            $this->validCreatePayload($offer->id, $company->id, [
                'availability_window_start' => '2026-12-31T18:00:00Z',
                'availability_window_end' => '2026-01-01T10:00:00Z',
            ]),
            $this->authHeaders($user)
        )->assertStatus(422)->assertJsonValidationErrors(['availability_window_end']);
    }

    public function test_index_filters_by_core_fields(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $headers = $this->authHeaders($user);

        $o1 = $this->makeCarOffer($company, 'A');
        $o2 = $this->makeCarOffer($company, 'B');

        $this->postJson('/api/cars', $this->validCreatePayload($o1->id, $company->id, [
            'vehicle_type' => 'suv',
            'brand' => 'Ford',
            'status' => 'published',
        ]), $headers)->assertStatus(201);

        $this->postJson('/api/cars', $this->validCreatePayload($o2->id, $company->id, [
            'vehicle_type' => 'sedan',
            'brand' => 'Toyota',
            'status' => 'draft',
        ]), $headers)->assertStatus(201);

        $this->getJson('/api/cars?vehicle_type=suv', $headers)
            ->assertOk()
            ->assertJsonPath('success', true);

        $json = $this->getJson('/api/cars?vehicle_type=suv', $headers)->json('data');
        $this->assertCount(1, $json);
        $this->assertSame('suv', $json[0]['vehicle_type']);

        $this->getJson('/api/cars?vehicle_type=sedan&status=draft', $headers)
            ->assertOk();
        $json2 = $this->getJson('/api/cars?vehicle_type=sedan&status=draft', $headers)->json('data');
        $this->assertCount(1, $json2);
        $this->assertSame('sedan', $json2[0]['vehicle_type']);
    }

    public function test_index_filters_by_origin_and_base_price(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $headers = $this->authHeaders($user);

        $o1 = $this->makeCarOffer($company, 'Car A');
        $o2 = $this->makeCarOffer($company, 'Car B');

        $this->postJson('/api/cars', $this->validCreatePayload($o1->id, $company->id, [
            'pickup_location' => 'Yerevan, AM',
            'dropoff_location' => 'Yerevan, AM',
            'base_price' => 100,
        ]), $headers)->assertStatus(201);

        $this->postJson('/api/cars', $this->validCreatePayload($o2->id, $company->id, [
            'pickup_location' => 'Gyumri, AM',
            'dropoff_location' => 'Gyumri, AM',
            'base_price' => 40,
        ]), $headers)->assertStatus(201);

        $json = $this->getJson('/api/cars?origin=Yerevan&base_price_min=90', $headers)->json('data');
        $this->assertCount(1, $json);
        $this->assertStringContainsString('Yerevan', (string) $json[0]['pickup_location']);
    }

    public function test_update_partial_expanded_fields(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $headers = $this->authHeaders($user);
        $offer = $this->makeCarOffer($company);

        $id = (int) $this->postJson(
            '/api/cars',
            $this->validCreatePayload($offer->id, $company->id),
            $headers
        )->json('data.id');

        $this->patchJson('/api/cars/'.$id, [
            'brand' => 'Honda',
            'seats' => 4,
            'status' => 'published',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.brand', 'Honda')
            ->assertJsonPath('data.seats', 4)
            ->assertJsonPath('data.status', 'published');

        $this->assertEquals('published', Car::query()->findOrFail($id)->status);
    }

    public function test_store_persists_advanced_options_and_returns_normalized_envelope(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $offer = $this->makeCarOffer($company);

        $ao = [
            'child_seats' => [
                'available' => true,
                'types' => ['infant', 'booster'],
            ],
            'extra_luggage' => [
                'additional_suitcases_max' => 2,
                'additional_small_bags_max' => 1,
                'notes' => 'Roof box on request',
            ],
            'services' => ['wifi', 'ac', 'gps'],
            'driver_languages' => ['en', 'hy', 'ru'],
        ];

        $res = $this->postJson(
            '/api/cars',
            array_merge($this->validCreatePayload($offer->id, $company->id), ['advanced_options' => $ao]),
            $this->authHeaders($user)
        );

        $res->assertStatus(201)
            ->assertJsonPath('data.advanced_options.v', 1)
            ->assertJsonPath('data.advanced_options.child_seats.available', true)
            ->assertJsonPath('data.advanced_options.services', ['ac', 'gps', 'wifi']);

        $id = (int) $res->json('data.id');
        $row = Car::query()->findOrFail($id);
        $this->assertIsArray($row->advanced_options);
        $this->assertTrue($row->advanced_options['child_seats']['available']);
        $this->assertSame(['booster', 'infant'], $row->advanced_options['child_seats']['types']);
    }

    public function test_store_rejects_invalid_advanced_service_key(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $offer = $this->makeCarOffer($company);

        $this->postJson(
            '/api/cars',
            array_merge($this->validCreatePayload($offer->id, $company->id), [
                'advanced_options' => ['services' => ['not_a_real_service']],
            ]),
            $this->authHeaders($user)
        )->assertStatus(422)->assertJsonValidationErrors(['advanced_options.services.0']);
    }

    public function test_update_merges_advanced_options_partial_patch(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $headers = $this->authHeaders($user);
        $offer = $this->makeCarOffer($company);

        $id = (int) $this->postJson(
            '/api/cars',
            array_merge($this->validCreatePayload($offer->id, $company->id), [
                'advanced_options' => [
                    'driver_languages' => ['de'],
                    'services' => ['bluetooth'],
                ],
            ]),
            $headers
        )->json('data.id');

        $this->patchJson('/api/cars/'.$id, [
            'advanced_options' => ['services' => ['wifi', 'ac']],
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.advanced_options.services', ['ac', 'wifi'])
            ->assertJsonPath('data.advanced_options.driver_languages', ['de']);

        $this->patchJson('/api/cars/'.$id, [
            'advanced_options' => null,
        ], $headers)->assertOk()->assertJsonPath('data.advanced_options.v', 1);

        $this->assertNull(Car::query()->findOrFail($id)->advanced_options);
    }

    public function test_store_rejects_limited_mileage_without_included_km(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $offer = $this->makeCarOffer($company);

        $this->postJson(
            '/api/cars',
            array_merge($this->validCreatePayload($offer->id, $company->id), [
                'advanced_options' => [
                    'pricing_rules' => [
                        'mileage' => ['mode' => 'limited'],
                    ],
                ],
            ]),
            $this->authHeaders($user)
        )->assertStatus(422)->assertJsonValidationErrors(['advanced_options.pricing_rules.mileage.included_km_per_rental']);
    }

    public function test_store_rejects_cross_border_surcharge_without_amount(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $offer = $this->makeCarOffer($company);

        $this->postJson(
            '/api/cars',
            array_merge($this->validCreatePayload($offer->id, $company->id), [
                'advanced_options' => [
                    'pricing_rules' => [
                        'cross_border' => ['policy' => 'surcharge_fixed'],
                    ],
                ],
            ]),
            $this->authHeaders($user)
        )->assertStatus(422)->assertJsonValidationErrors(['advanced_options.pricing_rules.cross_border.surcharge_amount']);
    }

    public function test_store_rejects_radius_with_not_applicable_out_of_radius(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $offer = $this->makeCarOffer($company);

        $this->postJson(
            '/api/cars',
            array_merge($this->validCreatePayload($offer->id, $company->id), [
                'advanced_options' => [
                    'pricing_rules' => [
                        'radius' => [
                            'service_radius_km' => 50,
                            'out_of_radius_mode' => 'not_applicable',
                        ],
                    ],
                ],
            ]),
            $this->authHeaders($user)
        )->assertStatus(422)->assertJsonValidationErrors(['advanced_options.pricing_rules.radius.out_of_radius_mode']);
    }

    public function test_store_persists_pricing_rules_c2_envelope(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $offer = $this->makeCarOffer($company);

        $this->postJson(
            '/api/cars',
            array_merge($this->validCreatePayload($offer->id, $company->id), [
                'advanced_options' => [
                    'pricing_rules' => [
                        'mileage' => [
                            'mode' => 'limited',
                            'included_km_per_rental' => 200,
                            'extra_km_price' => 0.25,
                        ],
                        'cross_border' => [
                            'policy' => 'surcharge_fixed',
                            'surcharge_amount' => 50,
                        ],
                        'radius' => [
                            'service_radius_km' => 80,
                            'out_of_radius_mode' => 'not_allowed',
                        ],
                    ],
                ],
            ]),
            $this->authHeaders($user)
        )->assertStatus(201)
            ->assertJsonPath('data.advanced_options.pricing_rules.mileage.mode', 'limited')
            ->assertJsonPath('data.advanced_options.pricing_rules.mileage.included_km_per_rental', 200)
            ->assertJsonPath('data.advanced_options.pricing_rules.cross_border.policy', 'surcharge_fixed')
            ->assertJsonPath('data.advanced_options.pricing_rules.radius.out_of_radius_mode', 'not_allowed');
    }

    public function test_store_rejects_unlimited_mileage_with_included_km(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $offer = $this->makeCarOffer($company);

        $this->postJson(
            '/api/cars',
            array_merge($this->validCreatePayload($offer->id, $company->id), [
                'advanced_options' => [
                    'pricing_rules' => [
                        'mileage' => [
                            'mode' => 'unlimited',
                            'included_km_per_rental' => 100,
                        ],
                    ],
                ],
            ]),
            $this->authHeaders($user)
        )->assertStatus(422)->assertJsonValidationErrors(['advanced_options.pricing_rules.mileage.included_km_per_rental']);
    }

    public function test_store_rejects_out_of_radius_flat_fee_when_mode_is_not_flat_fee(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $offer = $this->makeCarOffer($company);

        $this->postJson(
            '/api/cars',
            array_merge($this->validCreatePayload($offer->id, $company->id), [
                'advanced_options' => [
                    'pricing_rules' => [
                        'radius' => [
                            'service_radius_km' => 40,
                            'out_of_radius_mode' => 'per_km',
                            'out_of_radius_per_km' => 1.5,
                            'out_of_radius_flat_fee' => 20,
                        ],
                    ],
                ],
            ]),
            $this->authHeaders($user)
        )->assertStatus(422)->assertJsonValidationErrors(['advanced_options.pricing_rules.radius.out_of_radius_flat_fee']);
    }

    public function test_update_clears_stale_out_of_radius_fees_when_mode_changes_in_patch(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createCarCrudUser($company);
        $offer = $this->makeCarOffer($company);

        $id = $this->postJson(
            '/api/cars',
            array_merge($this->validCreatePayload($offer->id, $company->id), [
                'advanced_options' => [
                    'pricing_rules' => [
                        'radius' => [
                            'service_radius_km' => 40,
                            'out_of_radius_mode' => 'per_km',
                            'out_of_radius_per_km' => 2,
                        ],
                    ],
                ],
            ]),
            $this->authHeaders($user)
        )->assertStatus(201)->json('data.id');

        $this->patchJson('/api/cars/'.$id, [
            'advanced_options' => [
                'pricing_rules' => [
                    'radius' => [
                        'out_of_radius_mode' => 'not_allowed',
                    ],
                ],
            ],
        ], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.advanced_options.pricing_rules.radius.out_of_radius_mode', 'not_allowed')
            ->assertJsonPath('data.advanced_options.pricing_rules.radius.out_of_radius_per_km', null);
    }
}

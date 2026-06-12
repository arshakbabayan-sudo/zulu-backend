<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ConnectorDemoController;
use App\Models\Car;
use App\Models\Company;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\Offer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupplierConnection;
use App\Models\SupplierImportedItem;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserCompany;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Roadmap §4 — External API supplier connections (test / import / disconnect).
 */
class SupplierConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://ext.test/connector';

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

    private function createOffersCrudUser(Company $company, string $email): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'tdd_supplier_conn_'.$company->id]);
        $ids = Permission::query()->whereIn('name', ['offers.view', 'offers.create'])->pluck('id')->all();
        $role->permissions()->sync($ids);

        $user = User::query()->create([
            'name' => 'Supplier conn user',
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

    private function makeConnection(Company $company): SupplierConnection
    {
        return SupplierConnection::query()->create([
            'company_id' => $company->id,
            'name' => 'Test base',
            'base_url' => self::BASE,
            'login' => 'login',
            'password' => 'secret',
        ]);
    }

    private function cityName(): string
    {
        return (string) Location::query()->findOrFail($this->locationIds()['yerevan_city'])->name;
    }

    private function secondCityName(): string
    {
        return (string) Location::query()->findOrFail($this->locationIds()['gyumri_city'])->name;
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryPayload(string $city, string $secondCity): array
    {
        return [
            'items' => [
                [
                    'type' => 'hotel',
                    'external_id' => 'EXT-H1',
                    'title' => 'Ext Hotel One',
                    'city' => $city,
                    'price' => 90,
                    'currency' => 'USD',
                    'rooms' => [
                        ['room_name' => 'Std', 'room_type' => 'double', 'max_adults' => 2, 'max_total_guests' => 2, 'price' => 90, 'currency' => 'USD'],
                    ],
                ],
                [
                    'type' => 'car',
                    'external_id' => 'EXT-C1',
                    'title' => 'Ext Car One',
                    'city' => $city,
                    'pickup_location' => 'Airport',
                    'dropoff_location' => 'Airport',
                    'vehicle_class' => 'economy',
                    'price' => 30,
                    'currency' => 'USD',
                ],
                [
                    'type' => 'transfer',
                    'external_id' => 'EXT-T1',
                    'title' => 'Ext Transfer One',
                    'origin_city' => $city,
                    'destination_city' => $secondCity,
                    'price' => 20,
                    'currency' => 'USD',
                ],
                [
                    'type' => 'hotel',
                    'external_id' => 'EXT-H-BADCITY',
                    'title' => 'Ext Hotel Unknown City',
                    'city' => 'Nowhereville',
                    'price' => 50,
                    'currency' => 'USD',
                ],
                [
                    'type' => 'excursion',
                    'external_id' => 'EXT-X1',
                    'title' => 'Unsupported type item',
                    'price' => 10,
                    'currency' => 'USD',
                ],
            ],
            'next_page' => null,
        ];
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $this->getJson('/api/supplier-connections')->assertUnauthorized();
        $this->postJson('/api/supplier-connections', [])->assertUnauthorized();
        $this->postJson('/api/supplier-connections/1/test')->assertUnauthorized();
        $this->postJson('/api/supplier-connections/1/import')->assertUnauthorized();
        $this->deleteJson('/api/supplier-connections/1')->assertUnauthorized();
    }

    public function test_full_round_trip_create_test_import_reimport(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createOffersCrudUser($company, 'conn-a@tdd.local');
        $city = $this->cityName();

        Http::fake([
            self::BASE.'/ping*' => Http::response(['ok' => true]),
            self::BASE.'/inventory*' => Http::response($this->inventoryPayload($city, $this->secondCityName())),
        ]);

        $connectionId = $this->postJson('/api/supplier-connections', [
            'base_url' => self::BASE,
            'login' => 'login',
            'password' => 'secret',
        ], $this->authHeaders($user))
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'untested')
            ->json('data.id');

        $this->resetAuthGuards();

        $this->postJson('/api/supplier-connections/'.$connectionId.'/test', [], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.connection.status', 'ok');

        $this->resetAuthGuards();

        $import = $this->postJson('/api/supplier-connections/'.$connectionId.'/import', [], $this->authHeaders($user))
            ->assertOk()
            ->json('data.summary');

        $this->assertTrue($import['ok']);
        $this->assertSame(3, $import['created']);
        $this->assertSame(0, $import['updated']);
        $this->assertCount(2, $import['skipped']);
        $reasons = implode(' ', array_column($import['skipped'], 'reason'));
        $this->assertStringContainsString('Nowhereville', $reasons);
        $this->assertStringContainsString('Unsupported item type', $reasons);

        // Draft offers + module rows landed for the company.
        $hotelOffer = Offer::query()->where('title', 'Ext Hotel One')->firstOrFail();
        $this->assertSame('draft', $hotelOffer->status);
        $this->assertSame((int) $company->id, (int) $hotelOffer->company_id);
        $this->assertTrue(Hotel::query()->where('offer_id', $hotelOffer->id)->exists());
        $this->assertTrue(Car::query()->whereHas('offer', fn ($q) => $q->where('title', 'Ext Car One'))->exists());
        $this->assertTrue(Transfer::query()->where('transfer_title', 'Ext Transfer One')->exists());
        $this->assertSame(3, SupplierImportedItem::query()->where('supplier_connection_id', $connectionId)->count());

        // Re-import: idempotent — updates, no duplicates.
        $this->resetAuthGuards();
        $reimport = $this->postJson('/api/supplier-connections/'.$connectionId.'/import', [], $this->authHeaders($user))
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(0, $reimport['created']);
        $this->assertSame(3, $reimport['updated']);
        $this->assertSame(1, Offer::query()->where('title', 'Ext Hotel One')->count());
        $this->assertSame(3, SupplierImportedItem::query()->where('supplier_connection_id', $connectionId)->count());
    }

    public function test_failed_ping_marks_connection_failed(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $user = $this->createOffersCrudUser($company, 'conn-fail@tdd.local');
        $connection = $this->makeConnection($company);

        Http::fake([self::BASE.'/ping*' => Http::response(['error' => 'nope'], 401)]);

        $this->postJson('/api/supplier-connections/'.$connection->id.'/test', [], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.connection.status', 'failed');

        $this->assertNotNull($connection->fresh()->last_error);
    }

    public function test_tenant_isolation_and_disconnect_keeps_imported_offers(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $companyA = Company::query()->firstOrFail();
        $companyB = Company::query()->create(['name' => 'Other Co', 'type' => 'operator']);
        $userA = $this->createOffersCrudUser($companyA, 'conn-a2@tdd.local');
        $userB = $this->createOffersCrudUser($companyB, 'conn-b2@tdd.local');
        $connection = $this->makeConnection($companyA);

        // Foreign company: invisible (list empty, actions 404).
        $this->getJson('/api/supplier-connections', $this->authHeaders($userB))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->resetAuthGuards();
        $this->postJson('/api/supplier-connections/'.$connection->id.'/test', [], $this->authHeaders($userB))
            ->assertStatus(404);
        $this->resetAuthGuards();
        $this->deleteJson('/api/supplier-connections/'.$connection->id, [], $this->authHeaders($userB))
            ->assertStatus(404);

        // Owner disconnects — ledger cascades, imported offers stay.
        $offer = Offer::query()->create([
            'company_id' => $companyA->id,
            'type' => 'hotel',
            'title' => 'Imported keepme',
            'price' => 10,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        SupplierImportedItem::query()->create([
            'supplier_connection_id' => $connection->id,
            'item_type' => 'hotel',
            'external_id' => 'KEEP-1',
            'offer_id' => $offer->id,
        ]);

        $this->resetAuthGuards();
        $this->deleteJson('/api/supplier-connections/'.$connection->id, [], $this->authHeaders($userA))
            ->assertOk();

        $this->assertSame(0, SupplierImportedItem::query()->count());
        $this->assertNotNull($offer->fresh());
    }

    public function test_demo_connector_requires_demo_credentials_and_serves_inventory(): void
    {
        $this->getJson('/api/connector-demo/ping')->assertStatus(401);

        $headers = ['Authorization' => 'Basic '.base64_encode(ConnectorDemoController::DEMO_LOGIN.':'.ConnectorDemoController::DEMO_PASSWORD)];

        $this->getJson('/api/connector-demo/ping', $headers)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $inventory = $this->getJson('/api/connector-demo/inventory', $headers)
            ->assertOk()
            ->json();

        $this->assertNull($inventory['next_page']);
        $this->assertCount(4, $inventory['items']);
        $this->assertSame(['hotel', 'hotel', 'car', 'transfer'], array_column($inventory['items'], 'type'));
    }
}

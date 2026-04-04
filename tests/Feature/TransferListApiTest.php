<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use App\Services\Transfers\TransferService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransferListApiTest extends TestCase
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

    private function makeTransferOffer(Company $company, string $title = 'Ride'): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'transfer',
            'title' => $title,
            'price' => 50,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function transferPayload(int $offerId, array $overrides = []): array
    {
        return array_merge([
            'offer_id' => $offerId,
            'transfer_title' => 'Test Transfer',
            'transfer_type' => 'private_transfer',
            'pickup_country' => 'AM',
            'pickup_city' => 'Yerevan',
            'pickup_point_type' => 'airport',
            'pickup_point_name' => 'EVN',
            'dropoff_country' => 'AM',
            'dropoff_city' => 'Yerevan',
            'dropoff_point_type' => 'hotel',
            'dropoff_point_name' => 'Downtown',
            'vehicle_category' => 'sedan',
            'passenger_capacity' => 3,
            'luggage_capacity' => 2,
            'status' => 'draft',
            'availability_status' => 'available',
            'is_package_eligible' => false,
        ], $overrides);
    }

    /**
     * Logged-in user on $company without transfers.view (has offers.view only).
     */
    private function createUserWithoutTransfersView(Company $company): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'tdd_no_transfers_view']);
        $ids = Permission::query()->whereIn('name', [
            'offers.view',
            'companies.view',
        ])->pluck('id')->all();
        $role->permissions()->sync($ids);

        $user = User::query()->create([
            'name' => 'No transfer view',
            'email' => 'no-transfers-view@tdd.local',
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

    private function createAgentLinkedUser(Company $company): User
    {
        $agentRole = Role::query()->where('name', 'agent')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Agent transfer',
            'email' => 'tdd-agent-transfer@local.test',
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

    public function test_transfers_index_requires_authentication(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $this->getJson('/api/transfers')->assertUnauthorized();
        $this->getJson('/api/transfers/1')->assertUnauthorized();
    }

    public function test_transfers_scope_requires_transfers_view_permission(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $offer = $this->makeTransferOffer($company);
        $transfer = (new TransferService)->create($this->transferPayload($offer->id));
        $restricted = $this->createUserWithoutTransfersView($company);
        $headers = $this->authHeaders($restricted);

        $this->getJson('/api/transfers', $headers)->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/transfers/'.$transfer->id, $headers)
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_transfers_index_returns_paginated_data_when_page_present(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        (new TransferService)->create($this->transferPayload($this->makeTransferOffer($company)->id));

        $res = $this->getJson('/api/transfers?page=1', $this->authHeaders($user));
        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'last_page', 'total', 'per_page'],
            ]);
        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
    }

    public function test_transfers_show_returns_detail(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $transfer = (new TransferService)->create($this->transferPayload($this->makeTransferOffer($company)->id));

        $res = $this->getJson('/api/transfers/'.$transfer->id, $this->authHeaders($user));
        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.offer.type', 'transfer')
            ->assertJsonPath('data.transfer_title', 'Test Transfer');

        $this->assertArrayHasKey('cancellation_policy_type', $res->json('data'));
        $this->assertArrayHasKey('created_at', $res->json('data'));
    }

    public function test_transfers_index_excludes_rows_when_parent_offer_is_not_transfer_type(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeTransferOffer($company);
        $transfer = (new TransferService)->create($this->transferPayload($offer->id));

        DB::table('offers')->where('id', $offer->id)->update(['type' => 'flight']);

        $res = $this->getJson('/api/transfers', $this->authHeaders($user));
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertNotContains($transfer->id, $ids);
    }

    public function test_filter_company_id(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $c1 = Company::query()->firstOrFail();
        $c2 = Company::query()->create([
            'name' => 'Second Agency T',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $service = new TransferService;
        $t1 = $service->create($this->transferPayload($this->makeTransferOffer($c1, 'A')->id, ['transfer_title' => 'T1']));
        $t2 = $service->create($this->transferPayload($this->makeTransferOffer($c2, 'B')->id, ['transfer_title' => 'T2']));

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/transfers?company_id='.$c1->id, $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($t1->id, $ids);
        $this->assertNotContains($t2->id, $ids);
    }

    public function test_filter_status(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new TransferService;
        $ta = $service->create($this->transferPayload($this->makeTransferOffer($company, 'A')->id, ['status' => 'active', 'transfer_title' => 'TA']));
        $tb = $service->create($this->transferPayload($this->makeTransferOffer($company, 'B')->id, ['status' => 'draft', 'transfer_title' => 'TB']));

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/transfers?status=draft', $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($tb->id, $ids);
        $this->assertNotContains($ta->id, $ids);
    }

    public function test_filter_availability_status(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new TransferService;
        $t1 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'A')->id, [
            'availability_status' => 'available',
            'transfer_title' => 'T1',
        ]));
        $t2 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'B')->id, [
            'availability_status' => 'sold_out',
            'transfer_title' => 'T2',
        ]));

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/transfers?availability_status=sold_out', $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($t2->id, $ids);
        $this->assertNotContains($t1->id, $ids);
    }

    public function test_filter_transfer_type(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new TransferService;
        $t1 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'A')->id, [
            'transfer_type' => 'private_transfer',
            'transfer_title' => 'P',
        ]));
        $t2 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'B')->id, [
            'transfer_type' => 'shared_transfer',
            'transfer_title' => 'S',
        ]));

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/transfers?transfer_type=shared_transfer', $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($t2->id, $ids);
        $this->assertNotContains($t1->id, $ids);
    }

    public function test_filter_vehicle_category(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new TransferService;
        $t1 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'A')->id, [
            'vehicle_category' => 'sedan',
            'transfer_title' => 'Sed',
        ]));
        $t2 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'B')->id, [
            'vehicle_category' => 'minivan',
            'transfer_title' => 'Van',
        ]));

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/transfers?vehicle_category=minivan', $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($t2->id, $ids);
        $this->assertNotContains($t1->id, $ids);
    }

    public function test_filter_vehicle_type_query_alias_maps_to_vehicle_category(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new TransferService;
        $t1 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'A')->id, [
            'vehicle_category' => 'sedan',
            'transfer_title' => 'A',
        ]));
        $t2 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'B')->id, [
            'vehicle_category' => 'minibus',
            'transfer_title' => 'B',
        ]));

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/transfers?vehicle_type=minibus', $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($t2->id, $ids);
        $this->assertNotContains($t1->id, $ids);
    }

    public function test_filter_is_package_eligible(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new TransferService;
        $t1 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'A')->id, [
            'is_package_eligible' => true,
            'transfer_title' => 'Pkg',
        ]));
        $t2 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'B')->id, [
            'is_package_eligible' => false,
            'transfer_title' => 'No',
        ]));

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/transfers?is_package_eligible=1', $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($t1->id, $ids);
        $this->assertNotContains($t2->id, $ids);
    }

    public function test_filter_country_partial_matches_pickup_or_dropoff_country(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new TransferService;
        $t1 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'A')->id, [
            'pickup_country' => 'AM',
            'dropoff_country' => 'GE',
            'transfer_title' => 'AM->GE',
        ]));
        $t2 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'B')->id, [
            'pickup_country' => 'FR',
            'dropoff_country' => 'FR',
            'transfer_title' => 'FR',
        ]));

        $res = $this->getJson('/api/transfers?country=AM', $this->authHeaders($user));
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($t1->id, $ids);
        $this->assertNotContains($t2->id, $ids);
    }

    public function test_filter_origin_destination_partial_match_city_or_point_name(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new TransferService;
        $t1 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'A')->id, [
            'pickup_city' => 'Yerevan',
            'pickup_point_name' => 'EVN',
            'dropoff_city' => 'Tbilisi',
            'dropoff_point_name' => 'Central',
            'transfer_title' => 'YVN to TBS',
        ]));
        $t2 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'B')->id, [
            'pickup_city' => 'Paris',
            'pickup_point_name' => 'CDG',
            'dropoff_city' => 'Lyon',
            'dropoff_point_name' => 'Center',
            'transfer_title' => 'FR ride',
        ]));

        $res = $this->getJson('/api/transfers?origin=EVN&destination=Tbil', $this->authHeaders($user));
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($t1->id, $ids);
        $this->assertNotContains($t2->id, $ids);
    }

    public function test_filter_trip_date_range_and_passengers_and_price_bounds(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new TransferService;
        $t1 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'A')->id, [
            'service_date' => '2026-04-10',
            'passenger_capacity' => 4,
            'base_price' => 120,
            'transfer_title' => 'Match',
        ]));
        $t2 = $service->create($this->transferPayload($this->makeTransferOffer($company, 'B')->id, [
            'service_date' => '2026-04-20',
            'passenger_capacity' => 2,
            'base_price' => 300,
            'transfer_title' => 'No',
        ]));

        $qs = http_build_query([
            'trip_date_from' => '2026-04-01',
            'trip_date_to' => '2026-04-15',
            'passengers' => 3,
            'price_min' => 100,
            'price_max' => 200,
        ]);
        $res = $this->getJson('/api/transfers?'.$qs, $this->authHeaders($user));
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($t1->id, $ids);
        $this->assertNotContains($t2->id, $ids);
    }

    public function test_filter_user_email_invoice_id_and_order_number_via_offer_bookings(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new TransferService;
        $offer = $this->makeTransferOffer($company, 'A');
        $transfer = $service->create($this->transferPayload($offer->id, [
            'transfer_title' => 'Booked Transfer',
        ]));

        $booking = Booking::query()->create([
            'user_id' => $admin->id,
            'company_id' => $company->id,
            'status' => Booking::STATUS_CONFIRMED,
            'total_price' => 123.45,
        ]);
        BookingItem::query()->create([
            'booking_id' => $booking->id,
            'offer_id' => $offer->id,
            'price' => 123.45,
        ]);
        $invoice = Invoice::query()->create([
            'booking_id' => $booking->id,
            'unique_booking_reference' => 'ORDER-ABC-123',
            'total_amount' => 123.45,
            'currency' => 'USD',
            'status' => Invoice::STATUS_ISSUED,
            'vendor_locator' => 'VEND-42',
            'issuing_date' => '2026-04-01',
        ]);

        $headers = $this->authHeaders($admin);

        $byEmail = $this->getJson('/api/transfers?user_email=admin@zulu', $headers);
        $byEmail->assertOk();
        $this->assertContains($transfer->id, collect($byEmail->json('data'))->pluck('id')->all());

        $byInvoice = $this->getJson('/api/transfers?invoice_id='.$invoice->id, $headers);
        $byInvoice->assertOk();
        $this->assertContains($transfer->id, collect($byInvoice->json('data'))->pluck('id')->all());

        $byOrder = $this->getJson('/api/transfers?order_number=ORDER-ABC', $headers);
        $byOrder->assertOk();
        $this->assertContains($transfer->id, collect($byOrder->json('data'))->pluck('id')->all());
    }

    public function test_transfers_show_returns_404_when_not_found(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();

        $this->getJson('/api/transfers/999999', $this->authHeaders($user))
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_transfers_show_returns_404_when_out_of_scope_for_tenant_user(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $c1 = Company::query()->firstOrFail();
        $c2 = Company::query()->create([
            'name' => 'Other Co Transfer',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $transfer = (new TransferService)->create($this->transferPayload($this->makeTransferOffer($c2)->id));
        $agent = $this->createAgentLinkedUser($c1);

        $this->resetAuthGuards();

        $this->getJson('/api/transfers/'.$transfer->id, $this->authHeaders($agent))
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->resetAuthGuards();

        $this->getJson('/api/transfers/'.$transfer->id, $this->authHeaders($admin))
            ->assertOk()
            ->assertJsonPath('data.id', $transfer->id);
    }
}

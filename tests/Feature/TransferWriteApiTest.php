<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserCompany;
use App\Services\Admin\AdminAccessService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferWriteApiTest extends TestCase
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

    private function makeTransferOffer(Company $company, string $title = 'Ride', float $price = 55): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'transfer',
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
    private function validCreatePayload(int $offerId, array $overrides = []): array
    {
        $offerPrice = (float) Offer::query()->findOrFail($offerId)->price;

        return array_merge([
            'offer_id' => $offerId,
            'transfer_title' => 'API Transfer',
            'transfer_type' => 'private_transfer',
            'origin_location_id' => $this->locationIds()['yerevan_city'],
            'destination_location_id' => $this->locationIds()['gyumri_city'],
            'pickup_point_type' => 'airport',
            'pickup_point_name' => 'EVN',
            'dropoff_point_type' => 'address',
            'dropoff_point_name' => 'City center',
            'service_date' => '2026-09-01',
            'pickup_time' => '10:00:00',
            'estimated_duration_minutes' => 45,
            'vehicle_category' => 'sedan',
            'passenger_capacity' => 3,
            'luggage_capacity' => 2,
            'minimum_passengers' => 1,
            'maximum_passengers' => 3,
            'pricing_mode' => 'per_vehicle',
            'base_price' => $offerPrice,
            'cancellation_policy_type' => 'non_refundable',
            'status' => 'draft',
            'availability_status' => 'available',
        ], $overrides);
    }

    /**
     * Tenant user with transfers.view only (no platform.* permissions).
     * Seeded `agent` also receives platform.*.view perms, which classify as platform admin for commerce APIs.
     */
    private function createAgentLinkedUser(Company $company): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'tdd_transfer_view_only']);
        $viewId = Permission::query()->where('name', 'transfers.view')->value('id');
        $role->permissions()->sync(array_filter([$viewId]));

        $user = User::query()->create([
            'name' => 'Agent user',
            'email' => 'tdd-agent-transfer@local.test',
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

    private function createScopedTransferCrudUser(Company $company): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'tdd_transfer_scoped_crud']);
        $ids = Permission::query()->whereIn('name', [
            'transfers.view',
            'transfers.create',
            'transfers.update',
            'transfers.delete',
        ])->pluck('id')->all();
        $role->permissions()->sync($ids);

        $user = User::query()->create([
            'name' => 'Scoped transfer CRUD',
            'email' => 'transfer-scoped-crud@tdd.local',
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

    private function createTransferViewOnlyUser(Company $company): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'tdd_transfer_view_only']);
        $ids = Permission::query()->whereIn('name', [
            'transfers.view',
        ])->pluck('id')->all();
        $role->permissions()->sync($ids);

        $user = User::query()->create([
            'name' => 'Transfer view only',
            'email' => 'transfer-view-only@tdd.local',
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

    public function test_store_update_destroy_require_authentication(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $this->postJson('/api/transfers', [])->assertUnauthorized();
        $this->patchJson('/api/transfers/1', [])->assertUnauthorized();
        $this->deleteJson('/api/transfers/1')->assertUnauthorized();
    }

    public function test_store_update_destroy_require_write_permission(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $agentUser = $this->createAgentLinkedUser($company);
        $agentHeaders = $this->authHeaders($agentUser);
        $adminHeaders = $this->authHeaders($admin);

        $transferId = (int) $this->postJson(
            '/api/transfers',
            $this->validCreatePayload($this->makeTransferOffer($company, 'T2')->id),
            $adminHeaders
        )->assertStatus(201)->json('data.id');

        $this->resetAuthGuards();

        $this->patchJson('/api/transfers/'.$transferId, ['transfer_title' => 'Renamed'], $agentHeaders)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden');

        $this->resetAuthGuards();

        $this->deleteJson('/api/transfers/'.$transferId, [], $agentHeaders)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden');
    }

    public function test_agent_user_cannot_create_transfer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $agentUser = $this->createAgentLinkedUser($company);
        $offer = $this->makeTransferOffer($company);

        $this->postJson(
            '/api/transfers',
            $this->validCreatePayload($offer->id),
            $this->authHeaders($agentUser)
        )->assertStatus(403)->assertJsonPath('message', 'Forbidden');
    }

    public function test_store_creates_transfer_and_returns_detail(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeTransferOffer($company, 'OK', 88);

        $res = $this->postJson(
            '/api/transfers',
            $this->validCreatePayload($offer->id),
            $this->authHeaders($admin)
        );

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.offer.type', 'transfer')
            ->assertJsonPath('data.transfer_title', 'API Transfer');

        $offer->refresh();
        $this->assertEqualsWithDelta(88.0, (float) $offer->price, 0.01);
    }

    public function test_store_rejects_non_transfer_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => 'Stay',
            'price' => 10,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $this->postJson(
            '/api/transfers',
            $this->validCreatePayload($offer->id),
            $this->authHeaders($admin)
        )->assertStatus(422)->assertJsonValidationErrors(['offer_id']);
    }

    public function test_store_rejects_duplicate_transfer_per_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeTransferOffer($company);
        $headers = $this->authHeaders($admin);
        $payload = $this->validCreatePayload($offer->id);

        $this->postJson('/api/transfers', $payload, $headers)->assertStatus(201);
        $this->postJson('/api/transfers', $payload, $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offer_id']);
    }

    public function test_store_rejects_company_id_in_body(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeTransferOffer($company);
        $payload = $this->validCreatePayload($offer->id);
        $payload['company_id'] = $company->id;

        $this->postJson('/api/transfers', $payload, $this->authHeaders($admin))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_id']);
    }

    public function test_store_inherits_company_id_from_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeTransferOffer($company);

        $id = (int) $this->postJson(
            '/api/transfers',
            $this->validCreatePayload($offer->id),
            $this->authHeaders($admin)
        )->json('data.id');

        $transfer = Transfer::query()->findOrFail($id);
        $this->assertSame($company->id, $transfer->company_id);
        $this->assertSame($offer->id, $transfer->offer_id);
    }

    public function test_update_scalar_fields_and_rejects_locked_fields(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeTransferOffer($company);
        $headers = $this->authHeaders($admin);
        $transferId = (int) $this->postJson('/api/transfers', $this->validCreatePayload($offer->id), $headers)
            ->json('data.id');

        $this->patchJson('/api/transfers/'.$transferId, [
            'transfer_title' => 'Renamed',
            'passenger_capacity' => 5,
            'maximum_passengers' => 5,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.transfer_title', 'Renamed')
            ->assertJsonPath('data.passenger_capacity', 5);

        $this->patchJson('/api/transfers/'.$transferId, ['offer_id' => 999], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offer_id']);

        $this->patchJson('/api/transfers/'.$transferId, ['company_id' => 999], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_id']);
    }

    public function test_destroy_removes_transfer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeTransferOffer($company);
        $headers = $this->authHeaders($admin);
        $transferId = (int) $this->postJson('/api/transfers', $this->validCreatePayload($offer->id), $headers)
            ->assertStatus(201)
            ->json('data.id');

        $this->deleteJson('/api/transfers/'.$transferId, [], $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('transfers', ['id' => $transferId]);
    }

    public function test_update_and_delete_return_404_out_of_scope(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $c1 = Company::query()->firstOrFail();
        $c2 = Company::query()->create([
            'name' => 'Other tenant transfer',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $this->assertNotSame($c1->id, $c2->id);

        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $createRes = $this->postJson(
            '/api/transfers',
            $this->validCreatePayload($this->makeTransferOffer($c2)->id),
            $this->authHeaders($admin)
        )->assertStatus(201);
        $this->assertSame($c2->id, (int) $createRes->json('data.company_id'));
        $transferId = (int) $createRes->json('data.id');

        $this->resetAuthGuards();

        $scoped = $this->createScopedTransferCrudUser($c1);
        $this->assertFalse(app(AdminAccessService::class)->isSuperAdmin($scoped));
        $this->assertSame(
            [(int) $c1->id],
            app(AdminAccessService::class)->companyIdsForCommerceList($scoped, 'transfers.update')
        );
        $headers = $this->authHeaders($scoped);

        $this->patchJson('/api/transfers/'.$transferId, ['transfer_title' => 'X'], $headers)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Not found');

        $this->deleteJson('/api/transfers/'.$transferId, [], $headers)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Not found');
    }

    public function test_update_and_delete_return_403_when_same_company_but_insufficient_permission(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $offer = $this->makeTransferOffer($company);
        $transferId = (int) $this->postJson(
            '/api/transfers',
            $this->validCreatePayload($offer->id),
            $this->authHeaders($admin)
        )->assertStatus(201)->json('data.id');

        $this->resetAuthGuards();

        $viewOnly = $this->createTransferViewOnlyUser($company);
        $this->assertFalse(app(AdminAccessService::class)->isSuperAdmin($viewOnly));
        $headers = $this->authHeaders($viewOnly);
        $this->resetAuthGuards();

        $this->patchJson('/api/transfers/'.$transferId, ['transfer_title' => 'Nope'], $headers)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden');

        $this->resetAuthGuards();

        $this->deleteJson('/api/transfers/'.$transferId, [], $headers)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden');

        $this->assertDatabaseHas('transfers', ['id' => $transferId]);
    }
}

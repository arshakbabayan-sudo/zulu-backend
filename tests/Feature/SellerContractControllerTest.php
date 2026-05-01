<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\Contracts\ContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SellerContractControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/seller/contracts')->assertStatus(401);
    }

    public function test_index_returns_only_users_company_contracts(): void
    {
        $alice = $this->createSellerUser();
        $bob = $this->createSellerUser();

        $aliceCompany = $alice->companies()->first();
        $bobCompany = $bob->companies()->first();

        $aliceContract = $this->createPlatformContract($aliceCompany);
        $this->createPlatformContract($bobCompany); // not visible to alice

        Sanctum::actingAs($alice);
        $response = $this->getJson('/api/seller/contracts');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($aliceContract->id, $response->json('data.0.id'));
    }

    public function test_index_returns_empty_when_user_has_no_companies(): void
    {
        $loneUser = User::factory()->create();
        Sanctum::actingAs($loneUser);

        $response = $this->getJson('/api/seller/contracts');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_show_returns_owned_contract_with_versions(): void
    {
        $seller = $this->createSellerUser();
        $contract = $this->createPlatformContract($seller->companies()->first());

        Sanctum::actingAs($seller);
        $response = $this->getJson('/api/seller/contracts/'.$contract->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $contract->id);
        $this->assertGreaterThanOrEqual(1, count($response->json('data.versions')));
    }

    public function test_show_returns_404_for_other_users_contract(): void
    {
        $alice = $this->createSellerUser();
        $bob = $this->createSellerUser();
        $bobContract = $this->createPlatformContract($bob->companies()->first());

        Sanctum::actingAs($alice);
        $this->getJson('/api/seller/contracts/'.$bobContract->id)->assertStatus(404);
    }

    public function test_sign_as_party_b_records_signature_and_advances_status(): void
    {
        $seller = $this->createSellerUser();
        $contract = $this->createPlatformContract($seller->companies()->first());
        $contract->status = 'sent';
        $contract->save();

        Sanctum::actingAs($seller);
        $response = $this->postJson('/api/seller/contracts/'.$contract->id.'/sign');

        $response->assertOk();
        $response->assertJsonPath('data.status', 'signed_by_b');
        $this->assertCount(1, $response->json('data.signatures'));
    }

    public function test_sign_returns_404_for_other_users_contract(): void
    {
        $alice = $this->createSellerUser();
        $bob = $this->createSellerUser();
        $bobContract = $this->createPlatformContract($bob->companies()->first());
        $bobContract->status = 'sent';
        $bobContract->save();

        Sanctum::actingAs($alice);
        $this->postJson('/api/seller/contracts/'.$bobContract->id.'/sign')->assertStatus(404);
    }

    public function test_sign_returns_422_when_contract_already_terminated(): void
    {
        $seller = $this->createSellerUser();
        $contract = $this->createPlatformContract($seller->companies()->first());
        $contract->status = 'terminated';
        $contract->save();

        Sanctum::actingAs($seller);
        $this->postJson('/api/seller/contracts/'.$contract->id.'/sign')->assertStatus(422);
    }

    private function createSellerUser(): User
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Seller '.str()->random(6),
            'type' => 'operator',
            'status' => 'active',
        ]);

        $role = Role::query()->firstOrCreate(
            ['name' => 'Seller Test Role'],
            ['slug' => 'seller-test']
        );

        DB::table('user_company')->insert([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh(['companies']);
    }

    private function createPlatformContract(Company $sellerCompany): Contract
    {
        $template = ContractTemplate::query()->create([
            'name' => 'Test '.str()->random(4),
            'type' => 'platform',
            'language' => 'en',
            'version' => 1,
            'body' => 'Test body',
            'variables' => [],
            'active' => true,
        ]);

        $admin = User::factory()->create();

        return app(ContractService::class)->generateFromTemplate(
            $template,
            null,
            $sellerCompany,
            $admin,
            ['commission_clause' => [], 'payment_terms' => []],
        );
    }
}

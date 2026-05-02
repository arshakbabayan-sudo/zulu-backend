<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Connection;
use App\Models\Role;
use App\Models\User;
use App\Services\Partnerships\PartnerConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SellerConnectionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/seller/connections')->assertStatus(401);
    }

    public function test_index_returns_only_users_company_connections(): void
    {
        [$alice, $aliceCompany] = $this->createSellerUser();
        [$bob, $bobCompany] = $this->createSellerUser();
        $service = app(PartnerConnectionService::class);

        $other = $this->makeCompany();
        // alice's company → other (alice should see)
        $aliceConn = $service->propose($aliceCompany, $other, $alice);
        // bob's company → other (alice should NOT see)
        $service->propose($bobCompany, $other, $bob);

        Sanctum::actingAs($alice);
        $response = $this->getJson('/api/seller/connections');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($aliceConn->id, $response->json('data.0.id'));
    }

    public function test_store_creates_proposed_connection(): void
    {
        [$alice, $aliceCompany] = $this->createSellerUser();
        $counterparty = $this->makeCompany();

        Sanctum::actingAs($alice);
        $response = $this->postJson('/api/seller/connections', [
            'counterparty_company_id' => $counterparty->id,
            'type' => 'supplier_reseller',
            'direction' => 'a_to_b',
            'share_scope' => ['type' => 'categories', 'list' => ['hotel']],
            'territorial_scope' => ['AM'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'proposed');
        $response->assertJsonPath('data.seller_a_company_id', $aliceCompany->id);
        $response->assertJsonPath('data.seller_b_company_id', $counterparty->id);
    }

    public function test_store_returns_409_on_duplicate_open_connection(): void
    {
        [$alice, $aliceCompany] = $this->createSellerUser();
        $counterparty = $this->makeCompany();
        app(PartnerConnectionService::class)->propose($aliceCompany, $counterparty, $alice);

        Sanctum::actingAs($alice);
        $response = $this->postJson('/api/seller/connections', [
            'counterparty_company_id' => $counterparty->id,
        ]);

        $response->assertStatus(409);
    }

    public function test_store_returns_404_when_counterparty_unknown(): void
    {
        [$alice] = $this->createSellerUser();

        Sanctum::actingAs($alice);
        $response = $this->postJson('/api/seller/connections', [
            'counterparty_company_id' => 999999,
        ]);

        $response->assertStatus(404);
    }

    public function test_show_returns_404_for_non_party_connection(): void
    {
        [$alice] = $this->createSellerUser();
        [$bob, $bobCompany] = $this->createSellerUser();
        $other = $this->makeCompany();
        $bobConn = app(PartnerConnectionService::class)->propose($bobCompany, $other, $bob);

        Sanctum::actingAs($alice);
        $this->getJson('/api/seller/connections/'.$bobConn->id)->assertStatus(404);
    }

    public function test_accept_by_counterparty_moves_to_active(): void
    {
        [$alice, $aliceCompany] = $this->createSellerUser();
        [$bob, $bobCompany] = $this->createSellerUser();
        $conn = app(PartnerConnectionService::class)->propose($aliceCompany, $bobCompany, $alice);

        Sanctum::actingAs($bob);
        $response = $this->postJson('/api/seller/connections/'.$conn->id.'/accept');

        $response->assertOk();
        $response->assertJsonPath('data.status', 'active');
    }

    public function test_accept_by_proposer_returns_403(): void
    {
        [$alice, $aliceCompany] = $this->createSellerUser();
        [$bob, $bobCompany] = $this->createSellerUser();
        $conn = app(PartnerConnectionService::class)->propose($aliceCompany, $bobCompany, $alice);

        Sanctum::actingAs($alice);
        $this->postJson('/api/seller/connections/'.$conn->id.'/accept')->assertStatus(403);
    }

    public function test_reject_by_counterparty_with_reason(): void
    {
        [$alice, $aliceCompany] = $this->createSellerUser();
        [$bob, $bobCompany] = $this->createSellerUser();
        $conn = app(PartnerConnectionService::class)->propose($aliceCompany, $bobCompany, $alice);

        Sanctum::actingAs($bob);
        $response = $this->postJson('/api/seller/connections/'.$conn->id.'/reject', [
            'reason' => 'Markets not aligned',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'rejected');
        $response->assertJsonPath('data.rejection_reason', 'Markets not aligned');
    }

    public function test_counter_swaps_proposer_role(): void
    {
        [$alice, $aliceCompany] = $this->createSellerUser();
        [$bob, $bobCompany] = $this->createSellerUser();
        $conn = app(PartnerConnectionService::class)->propose($aliceCompany, $bobCompany, $alice);

        Sanctum::actingAs($bob);
        $response = $this->postJson('/api/seller/connections/'.$conn->id.'/counter', [
            'direction' => 'both',
            'territorial_scope' => ['AM', 'GE'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'proposed');
        $response->assertJsonPath('data.direction', 'both');
        $response->assertJsonPath('data.proposed_by_user_id', $bob->id);
    }

    public function test_terminate_requires_reason(): void
    {
        [$alice, $aliceCompany] = $this->createSellerUser();
        [$bob, $bobCompany] = $this->createSellerUser();
        $conn = app(PartnerConnectionService::class)->propose($aliceCompany, $bobCompany, $alice);
        app(PartnerConnectionService::class)->accept($conn, $bob);

        Sanctum::actingAs($alice);
        $this->postJson('/api/seller/connections/'.$conn->id.'/terminate', [])->assertStatus(422);
    }

    public function test_terminate_with_reason_succeeds(): void
    {
        [$alice, $aliceCompany] = $this->createSellerUser();
        [$bob, $bobCompany] = $this->createSellerUser();
        $conn = app(PartnerConnectionService::class)->propose($aliceCompany, $bobCompany, $alice);
        app(PartnerConnectionService::class)->accept($conn, $bob);

        Sanctum::actingAs($alice);
        $response = $this->postJson('/api/seller/connections/'.$conn->id.'/terminate', [
            'reason' => 'End of season',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'terminated');
    }

    public function test_pause_and_resume_cycle(): void
    {
        [$alice, $aliceCompany] = $this->createSellerUser();
        [$bob, $bobCompany] = $this->createSellerUser();
        $conn = app(PartnerConnectionService::class)->propose($aliceCompany, $bobCompany, $alice);
        app(PartnerConnectionService::class)->accept($conn, $bob);

        Sanctum::actingAs($alice);
        $this->postJson('/api/seller/connections/'.$conn->id.'/pause')
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');

        $this->postJson('/api/seller/connections/'.$conn->id.'/resume')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_visible_suppliers_lists_active_partners(): void
    {
        [$viewer, $viewerCompany] = $this->createSellerUser();
        [$supplierUser, $supplierCompany] = $this->createSellerUser();

        $conn = app(PartnerConnectionService::class)->propose($supplierCompany, $viewerCompany, $supplierUser);
        app(PartnerConnectionService::class)->accept($conn, $viewer);

        Sanctum::actingAs($viewer);
        $response = $this->getJson('/api/seller/visible-suppliers');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($supplierCompany->id, $response->json('data.0.id'));
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function createSellerUser(): array
    {
        $user = User::factory()->create();
        $company = $this->makeCompany();

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

        return [$user->fresh(), $company];
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Conn Test Co '.str()->random(6),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }
}

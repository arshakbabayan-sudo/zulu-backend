<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminContractControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_gets_403_on_index(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/platform-admin/contracts')->assertStatus(403);
    }

    public function test_store_creates_platform_contract_and_returns_v1(): void
    {
        $admin = $this->createPlatformAdmin();
        $template = $this->createTemplate('platform', 'Hi {{name}}, commission {{rate}}%.');
        $seller = $this->createCompany('Seller One');

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/contracts', [
            'template_id' => $template->id,
            'party_a_company_id' => null,
            'party_b_company_id' => $seller->id,
            'variables' => ['name' => 'Seller One', 'rate' => 12],
            'commission_clause' => ['rate_pct' => 12],
            'payment_terms' => ['frequency' => 'monthly'],
            'effective_date' => '2026-06-01',
            'language' => 'en',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'platform');
        $response->assertJsonPath('data.party_a_company_id', null);
        $response->assertJsonPath('data.party_b_company_id', $seller->id);
        $response->assertJsonPath('data.status', 'draft');
        $this->assertStringContainsString('CT-PLAT-', $response->json('data.contract_number'));
        $this->assertSame('Hi Seller One, commission 12%.', $response->json('data.rendered_body.html'));
    }

    public function test_store_returns_422_when_party_a_provided_for_platform_template(): void
    {
        $admin = $this->createPlatformAdmin();
        $template = $this->createTemplate('platform');
        $sellerA = $this->createCompany();
        $sellerB = $this->createCompany();

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/contracts', [
            'template_id' => $template->id,
            'party_a_company_id' => $sellerA->id, // invalid for platform
            'party_b_company_id' => $sellerB->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_send_transitions_draft_to_sent(): void
    {
        $admin = $this->createPlatformAdmin();
        $contract = $this->createDraftContract($admin);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/contracts/'.$contract->id.'/send');

        $response->assertOk();
        $response->assertJsonPath('data.status', 'sent');
    }

    public function test_send_returns_422_when_status_not_draft(): void
    {
        $admin = $this->createPlatformAdmin();
        $contract = $this->createDraftContract($admin);
        $contract->status = 'terminated';
        $contract->save();

        Sanctum::actingAs($admin);
        $this->postJson('/api/platform-admin/contracts/'.$contract->id.'/send')
            ->assertStatus(422);
    }

    public function test_countersign_after_seller_signed_promotes_to_active(): void
    {
        $admin = $this->createPlatformAdmin();
        $contract = $this->createDraftContract($admin);
        $sellerUser = User::factory()->create();

        Sanctum::actingAs($admin);
        $this->postJson('/api/platform-admin/contracts/'.$contract->id.'/send')->assertOk();

        // Simulate seller signing via service (Step 5 covers seller API; here we directly seed the signature state).
        $contract->refresh();
        $contract->signatures = [['party' => 'b', 'user_id' => $sellerUser->id, 'ip' => '1.1.1.1', 'timestamp' => now()->toIso8601String()]];
        $contract->status = 'signed_by_b';
        $contract->save();

        $response = $this->postJson('/api/platform-admin/contracts/'.$contract->id.'/countersign');
        $response->assertOk();
        $response->assertJsonPath('data.status', 'active');
    }

    public function test_terminate_requires_reason_and_records_it(): void
    {
        $admin = $this->createPlatformAdmin();
        $contract = $this->createDraftContract($admin);

        Sanctum::actingAs($admin);
        $missingReason = $this->postJson('/api/platform-admin/contracts/'.$contract->id.'/terminate', []);
        $missingReason->assertStatus(422);

        $response = $this->postJson('/api/platform-admin/contracts/'.$contract->id.'/terminate', [
            'reason' => 'Mutual cancellation',
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.status', 'terminated');
        $response->assertJsonPath('data.termination_reason', 'Mutual cancellation');
    }

    public function test_show_includes_versions(): void
    {
        $admin = $this->createPlatformAdmin();
        $contract = $this->createDraftContract($admin);

        Sanctum::actingAs($admin);
        $this->postJson('/api/platform-admin/contracts/'.$contract->id.'/send'); // creates v2

        $response = $this->getJson('/api/platform-admin/contracts/'.$contract->id);
        $response->assertOk();
        $this->assertGreaterThanOrEqual(2, count($response->json('data.versions')));
    }

    public function test_index_filters_by_status(): void
    {
        $admin = $this->createPlatformAdmin();
        $this->createDraftContract($admin);
        $sent = $this->createDraftContract($admin);
        $sent->status = 'sent';
        $sent->save();

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/contracts?status=sent');

        $response->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('sent', $response->json('data.0.status'));
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }

    private function createDraftContract(User $admin): Contract
    {
        $template = $this->createTemplate('platform');
        $seller = $this->createCompany();

        // Use service via app() (so DI works) — go through API for realism.
        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/contracts', [
            'template_id' => $template->id,
            'party_b_company_id' => $seller->id,
            'commission_clause' => ['rate_pct' => 10],
            'payment_terms' => ['frequency' => 'monthly'],
        ]);

        return Contract::query()->findOrFail($response->json('data.id'));
    }

    private function createTemplate(string $type, ?string $body = null): ContractTemplate
    {
        return ContractTemplate::query()->create([
            'name' => 'T '.str()->random(6),
            'type' => $type,
            'language' => 'en',
            'version' => 1,
            'body' => $body ?? 'Default body',
            'variables' => [],
            'active' => true,
        ]);
    }

    private function createCompany(?string $name = null): Company
    {
        return Company::query()->create([
            'name' => $name ?? 'Co '.str()->random(6),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }
}

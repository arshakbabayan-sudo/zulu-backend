<?php

namespace Tests\Unit\Services\Contracts;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\ContractVersion;
use App\Models\User;
use App\Services\Contracts\ContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ContractServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContractService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContractService;
    }

    public function test_generate_platform_contract_substitutes_variables_and_creates_v1_snapshot(): void
    {
        $template = $this->createTemplate('platform', 'Hello {{seller_name}}, commission {{rate}}%.');
        $seller = $this->createCompany('My Hotels Ltd');
        $admin = User::factory()->create();

        $contract = $this->service->generateFromTemplate(
            $template,
            null,
            $seller,
            $admin,
            [
                'variables' => ['seller_name' => 'My Hotels Ltd', 'rate' => 12],
                'commission_clause' => ['rate_pct' => 12],
                'payment_terms' => ['frequency' => 'monthly'],
                'language' => 'en',
            ],
        );

        $this->assertSame('platform', $contract->type);
        $this->assertNull($contract->party_a_company_id);
        $this->assertSame($seller->id, $contract->party_b_company_id);
        $this->assertSame('draft', $contract->status);
        $this->assertSame('Hello My Hotels Ltd, commission 12%.', $contract->rendered_body['html']);
        $this->assertStringStartsWith('CT-PLAT-', $contract->contract_number);

        $versions = ContractVersion::query()->where('contract_id', $contract->id)->get();
        $this->assertCount(1, $versions);
        $this->assertSame(1, $versions->first()->version_number);
    }

    public function test_generate_partner_agreement_requires_both_parties(): void
    {
        $template = $this->createTemplate('partner_default');
        $sellerA = $this->createCompany();
        $sellerB = $this->createCompany();
        $admin = User::factory()->create();

        $contract = $this->service->generateFromTemplate($template, $sellerA, $sellerB, $admin);

        $this->assertSame('partner', $contract->type);
        $this->assertSame($sellerA->id, $contract->party_a_company_id);
        $this->assertSame($sellerB->id, $contract->party_b_company_id);
        $this->assertStringStartsWith('CT-PART-', $contract->contract_number);
    }

    public function test_generate_platform_contract_rejects_non_null_party_a(): void
    {
        $template = $this->createTemplate('platform');
        $sellerA = $this->createCompany();
        $sellerB = $this->createCompany();
        $admin = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->generateFromTemplate($template, $sellerA, $sellerB, $admin);
    }

    public function test_generate_partner_agreement_rejects_null_party_a(): void
    {
        $template = $this->createTemplate('partner_default');
        $seller = $this->createCompany();
        $admin = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->generateFromTemplate($template, null, $seller, $admin);
    }

    public function test_send_transitions_draft_to_sent_and_snapshots_version(): void
    {
        $contract = $this->createDraftPlatformContract();
        $admin = User::query()->find($contract->created_by_user_id);

        $sent = $this->service->send($contract, $admin);

        $this->assertSame('sent', $sent->status);
        $this->assertSame(2, ContractVersion::query()->where('contract_id', $contract->id)->count());
    }

    public function test_sign_by_party_b_then_party_a_results_in_countersigned_active(): void
    {
        $contract = $this->createDraftPlatformContract();
        $admin = User::query()->find($contract->created_by_user_id);
        $sellerUser = User::factory()->create();

        $this->service->send($contract, $admin);

        $afterB = $this->service->signAsPartyB($contract->fresh(), $sellerUser, '203.0.113.5');
        $this->assertSame('signed_by_b', $afterB->status);
        $this->assertCount(1, $afterB->signatures);

        $afterA = $this->service->signAsPartyA($afterB, $admin, '203.0.113.6');
        $this->assertSame('active', $afterA->status, 'Both parties signed + no future effective_date → active.');
        $this->assertCount(2, $afterA->signatures);
    }

    public function test_sign_with_future_effective_date_results_in_countersigned_not_active(): void
    {
        $contract = $this->createDraftPlatformContract([
            'effective_date' => now()->addDays(30)->toDateString(),
        ]);
        $admin = User::query()->find($contract->created_by_user_id);
        $sellerUser = User::factory()->create();

        $this->service->signAsPartyB($contract, $sellerUser, '203.0.113.5');
        $signed = $this->service->signAsPartyA($contract->fresh(), $admin, '203.0.113.6');

        $this->assertSame('countersigned', $signed->status);
    }

    public function test_terminate_sets_status_reason_and_timestamp(): void
    {
        $contract = $this->createDraftPlatformContract();
        $admin = User::query()->find($contract->created_by_user_id);

        $terminated = $this->service->terminate($contract, 'Mutual cancellation', $admin);

        $this->assertSame('terminated', $terminated->status);
        $this->assertSame('Mutual cancellation', $terminated->termination_reason);
        $this->assertNotNull($terminated->terminated_at);
    }

    public function test_terminate_rejects_already_terminated(): void
    {
        $contract = $this->createDraftPlatformContract();
        $admin = User::query()->find($contract->created_by_user_id);

        $this->service->terminate($contract, 'first', $admin);

        $this->expectException(RuntimeException::class);
        $this->service->terminate($contract->fresh(), 'second', $admin);
    }

    public function test_render_body_keeps_unmatched_placeholders_intact(): void
    {
        $template = $this->createTemplate('platform', 'Hi {{a}} and {{b}} and {{c}}');

        $rendered = $this->service->renderBody($template, ['a' => 'Alice', 'c' => 'Carol']);

        $this->assertSame('Hi Alice and {{b}} and Carol', $rendered);
    }

    public function test_send_rejects_invalid_transition(): void
    {
        $contract = $this->createDraftPlatformContract();
        $admin = User::query()->find($contract->created_by_user_id);

        $this->service->terminate($contract, 'reason', $admin);

        $this->expectException(RuntimeException::class);
        $this->service->send($contract->fresh(), $admin); // can't transition from terminated
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createDraftPlatformContract(array $extra = []): Contract
    {
        $template = $this->createTemplate('platform');
        $seller = $this->createCompany();
        $admin = User::factory()->create();

        return $this->service->generateFromTemplate(
            $template,
            null,
            $seller,
            $admin,
            array_merge([
                'commission_clause' => ['rate_pct' => 10],
                'payment_terms' => ['frequency' => 'monthly'],
            ], $extra),
        );
    }

    private function createTemplate(string $type, ?string $body = null): ContractTemplate
    {
        return ContractTemplate::query()->create([
            'name' => 'T '.$type.' '.str()->random(4),
            'type' => $type,
            'language' => 'en',
            'version' => 1,
            'body' => $body ?? 'Default template body.',
            'variables' => [],
            'active' => true,
        ]);
    }

    private function createCompany(?string $name = null): Company
    {
        return Company::query()->create([
            'name' => $name ?? 'Company '.str()->random(6),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }
}

<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\ContractVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_platform_contract_with_null_party_a(): void
    {
        $template = $this->createTemplate('platform');
        $seller = $this->createCompany();
        $admin = User::factory()->create();

        $contract = Contract::query()->create([
            'contract_number' => 'CT-PLAT-001',
            'type' => 'platform',
            'party_a_company_id' => null, // ZULU side
            'party_b_company_id' => $seller->id,
            'template_id' => $template->id,
            'status' => 'draft',
            'commission_clause' => ['rate_pct' => 10, 'currency' => 'EUR'],
            'payment_terms' => ['frequency' => 'monthly', 'method' => 'bank_transfer'],
            'rendered_body' => ['html' => '<p>Hello</p>'],
            'auto_renew' => true,
            'termination_notice_days' => 30,
            'created_by_user_id' => $admin->id,
            'language' => 'en',
        ]);

        $fresh = Contract::query()->findOrFail($contract->id);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $fresh->id);
        $this->assertSame('platform', $fresh->type);
        $this->assertNull($fresh->party_a_company_id);
        $this->assertSame($seller->id, $fresh->party_b_company_id);
        $this->assertSame(10, $fresh->commission_clause['rate_pct']);
        $this->assertSame('monthly', $fresh->payment_terms['frequency']);
        $this->assertTrue($fresh->auto_renew);
        $this->assertTrue($fresh->isPlatformContract());
        $this->assertFalse($fresh->isPartnerAgreement());
    }

    public function test_persists_partner_agreement_with_both_parties(): void
    {
        $template = $this->createTemplate('partner_default');
        $sellerA = $this->createCompany();
        $sellerB = $this->createCompany();
        $admin = User::factory()->create();

        $contract = Contract::query()->create([
            'contract_number' => 'CT-PART-001',
            'type' => 'partner',
            'party_a_company_id' => $sellerA->id,
            'party_b_company_id' => $sellerB->id,
            'template_id' => $template->id,
            'status' => 'draft',
            'commission_clause' => [],
            'payment_terms' => ['method' => 'platform_default'],
            'rendered_body' => ['html' => 'partner body'],
            'created_by_user_id' => $admin->id,
            'language' => 'hy',
        ]);

        $fresh = $contract->fresh();
        $this->assertTrue($fresh->isPartnerAgreement());
        $this->assertSame($sellerA->id, $fresh->partyA->id);
        $this->assertSame($sellerB->id, $fresh->partyB->id);
        $this->assertInstanceOf(BelongsTo::class, $fresh->template());
        $this->assertSame($template->id, $fresh->template->id);
    }

    public function test_is_active_only_when_status_active_or_countersigned_and_not_expired(): void
    {
        $template = $this->createTemplate('platform');
        $seller = $this->createCompany();
        $admin = User::factory()->create();

        $base = [
            'contract_number' => 'CT-AC-'.str()->random(4),
            'type' => 'platform',
            'party_b_company_id' => $seller->id,
            'template_id' => $template->id,
            'commission_clause' => [],
            'payment_terms' => [],
            'rendered_body' => [],
            'created_by_user_id' => $admin->id,
            'language' => 'en',
        ];

        $draft = Contract::query()->create($base + ['status' => 'draft']);
        $active = Contract::query()->create(array_merge($base, ['status' => 'active', 'contract_number' => 'CT-AC-'.str()->random(4)]));
        $expired = Contract::query()->create(array_merge($base, ['status' => 'active', 'contract_number' => 'CT-AC-'.str()->random(4), 'expiry_date' => now()->subDay()]));
        $countersigned = Contract::query()->create(array_merge($base, ['status' => 'countersigned', 'contract_number' => 'CT-AC-'.str()->random(4)]));

        $this->assertFalse($draft->isActive());
        $this->assertTrue($active->isActive());
        $this->assertFalse($expired->isActive());
        $this->assertTrue($countersigned->isActive());
    }

    public function test_versions_relation_loads_history(): void
    {
        $template = $this->createTemplate('platform');
        $seller = $this->createCompany();
        $admin = User::factory()->create();

        $contract = Contract::query()->create([
            'contract_number' => 'CT-VER-001',
            'type' => 'platform',
            'party_b_company_id' => $seller->id,
            'template_id' => $template->id,
            'status' => 'draft',
            'commission_clause' => [],
            'payment_terms' => [],
            'rendered_body' => [],
            'created_by_user_id' => $admin->id,
            'language' => 'en',
        ]);

        ContractVersion::query()->create([
            'contract_id' => $contract->id,
            'version_number' => 1,
            'snapshot' => ['status' => 'draft'],
            'changed_by_user_id' => $admin->id,
            'changed_at' => now(),
        ]);
        ContractVersion::query()->create([
            'contract_id' => $contract->id,
            'version_number' => 2,
            'snapshot' => ['status' => 'sent'],
            'changed_by_user_id' => $admin->id,
            'changed_at' => now()->addSecond(),
        ]);

        $versions = $contract->fresh()->versions;
        $this->assertInstanceOf(HasMany::class, $contract->versions());
        $this->assertCount(2, $versions);
        $this->assertSame(2, $versions->first()->version_number); // ordered desc
    }

    private function createTemplate(string $type): ContractTemplate
    {
        return ContractTemplate::query()->create([
            'name' => 'Test '.$type.' template',
            'type' => $type,
            'language' => 'en',
            'version' => 1,
            'body' => 'Hello {{seller_name}}, commission {{commission_rate}}%.',
            'variables' => [
                ['key' => 'seller_name', 'label' => 'Seller name', 'type' => 'string'],
                ['key' => 'commission_rate', 'label' => 'Commission %', 'type' => 'number'],
            ],
            'active' => true,
        ]);
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Contract Test Co '.str()->random(6),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\ContractVersion;
use App\Models\User;
use App\Services\Contracts\ContractService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ContractAutoRenewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze time so the renewal window + extended-term assertions are exact.
        $this->travelTo(Carbon::parse('2026-06-17 12:00:00'));
    }

    public function test_renews_active_auto_renew_contract_inside_its_notice_window(): void
    {
        $admin = $this->createPlatformAdmin();
        // 5-whole-month term (Jan 1 → Jun 25), expiry 8 days out → inside the 30-day window.
        $contract = $this->createContract($admin, [
            'status' => 'active',
            'effective_date' => '2026-01-01',
            'expiry_date' => '2026-06-25',
            'auto_renew' => true,
            'termination_notice_days' => 30,
        ]);

        $renewed = app(ContractService::class)->renewDueContracts();

        $this->assertSame(1, $renewed);
        $contract->refresh();
        // Renewed by the original term (5 months) → 2026-06-25 + 5mo = 2026-11-25.
        $this->assertSame('2026-11-25', $contract->expiry_date->toDateString());
        $this->assertSame('active', $contract->status);
        // A version snapshot was taken for the renewal.
        $this->assertSame(1, ContractVersion::query()->where('contract_id', $contract->id)->count());
    }

    public function test_promotes_countersigned_contract_to_active_on_renewal(): void
    {
        $admin = $this->createPlatformAdmin();
        $contract = $this->createContract($admin, [
            'status' => 'countersigned',
            'effective_date' => '2026-01-01',
            'expiry_date' => '2026-06-20',
            'auto_renew' => true,
            'termination_notice_days' => 30,
        ]);

        $renewed = app(ContractService::class)->renewDueContracts();

        $this->assertSame(1, $renewed);
        $this->assertSame('active', $contract->refresh()->status);
    }

    public function test_does_not_renew_when_auto_renew_is_off(): void
    {
        $admin = $this->createPlatformAdmin();
        $contract = $this->createContract($admin, [
            'status' => 'active',
            'effective_date' => '2026-01-01',
            'expiry_date' => '2026-06-25',
            'auto_renew' => false,
            'termination_notice_days' => 30,
        ]);

        $this->assertSame(0, app(ContractService::class)->renewDueContracts());
        $this->assertSame('2026-06-25', $contract->refresh()->expiry_date->toDateString());
    }

    public function test_does_not_renew_when_expiry_is_outside_the_notice_window(): void
    {
        $admin = $this->createPlatformAdmin();
        // Expiry far away (Dec 31) — well beyond the 30-day notice window.
        $contract = $this->createContract($admin, [
            'status' => 'active',
            'effective_date' => '2026-01-01',
            'expiry_date' => '2026-12-31',
            'auto_renew' => true,
            'termination_notice_days' => 30,
        ]);

        $this->assertSame(0, app(ContractService::class)->renewDueContracts());
        $this->assertSame('2026-12-31', $contract->refresh()->expiry_date->toDateString());
    }

    public function test_does_not_renew_terminated_contract(): void
    {
        $admin = $this->createPlatformAdmin();
        $contract = $this->createContract($admin, [
            'status' => 'terminated',
            'effective_date' => '2026-01-01',
            'expiry_date' => '2026-06-25',
            'auto_renew' => true,
            'termination_notice_days' => 30,
            'terminated_at' => '2026-05-01',
        ]);

        $this->assertSame(0, app(ContractService::class)->renewDueContracts());
        $this->assertSame('2026-06-25', $contract->refresh()->expiry_date->toDateString());
    }

    public function test_command_runs_and_renews_due_contracts(): void
    {
        $admin = $this->createPlatformAdmin();
        $contract = $this->createContract($admin, [
            'status' => 'active',
            'effective_date' => '2026-01-01',
            'expiry_date' => '2026-06-25',
            'auto_renew' => true,
            'termination_notice_days' => 30,
        ]);

        $this->artisan('contracts:auto-renew')
            ->expectsOutputToContain('Auto-renewed 1 contract(s).')
            ->assertExitCode(0);

        $this->assertSame('2026-11-25', $contract->refresh()->expiry_date->toDateString());
    }

    // ── helpers ──────────────────────────────────────────────────────────
    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function createContract(User $admin, array $attrs): Contract
    {
        $template = ContractTemplate::query()->create([
            'name' => 'T '.str()->random(6),
            'type' => 'platform',
            'language' => 'en',
            'version' => 1,
            'body' => 'Default body',
            'variables' => [],
            'active' => true,
        ]);
        $company = Company::query()->create([
            'name' => 'Co '.str()->random(6),
            'type' => 'operator',
        ]);

        return Contract::query()->create(array_merge([
            'contract_number' => 'CT-TEST-'.strtoupper(str()->random(6)),
            'type' => 'platform',
            'party_a_company_id' => null,
            'party_b_company_id' => $company->id,
            'template_id' => $template->id,
            'rendered_body' => ['html' => 'body'],
            'language' => 'en',
            'created_by_user_id' => $admin->id,
        ], $attrs));
    }
}

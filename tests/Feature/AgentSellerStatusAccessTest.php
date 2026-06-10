<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap 10.06 §7 — an agency owner's membership role is `agent`, which the
 * operator-tier canManageCompany check rejects, so the owner got 403 opening
 * their own "Seller status" (CRM → My company pane and the old
 * /bucket3/seller-status page both call companies/{id}/seller-applications).
 * Agent-role members may view + submit seller applications for their OWN
 * company only.
 */
class AgentSellerStatusAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Role> */
    private array $roles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->roles = [
            'company_admin' => Role::updateOrCreate(['name' => 'company_admin'], ['scope' => Role::SCOPE_COMPANY]),
            'agent' => Role::updateOrCreate(['name' => 'agent'], ['scope' => Role::SCOPE_COMPANY]),
        ];
    }

    private function company(string $name): Company
    {
        return Company::create(['name' => $name, 'type' => 'agency']);
    }

    private function member(Company $company, string $roleKey): User
    {
        $user = User::factory()->create();
        UserCompany::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $this->roles[$roleKey]->id,
        ]);

        return $user;
    }

    public function test_agent_member_can_list_own_company_seller_applications(): void
    {
        $company = $this->company('Own Agency');
        $agent = $this->member($company, 'agent');

        Sanctum::actingAs($agent->fresh());

        $this->getJson("/api/companies/{$company->id}/seller-applications")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_agent_member_can_submit_seller_application(): void
    {
        $company = $this->company('Own Agency');
        $agent = $this->member($company, 'agent');

        Sanctum::actingAs($agent->fresh());

        $this->postJson("/api/companies/{$company->id}/seller-applications", [
            'service_type' => 'hotel',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('company_seller_applications', [
            'company_id' => $company->id,
            'service_type' => 'hotel',
        ]);
    }

    public function test_agent_of_another_company_is_forbidden(): void
    {
        $mine = $this->company('Mine');
        $other = $this->company('Other');
        $outsideAgent = $this->member($other, 'agent');

        Sanctum::actingAs($outsideAgent->fresh());

        $this->getJson("/api/companies/{$mine->id}/seller-applications")->assertForbidden();
        $this->postJson("/api/companies/{$mine->id}/seller-applications", [
            'service_type' => 'hotel',
        ])->assertForbidden();
    }

    public function test_company_admin_owner_still_has_access(): void
    {
        $company = $this->company('Owner Agency');
        $owner = $this->member($company, 'company_admin');

        Sanctum::actingAs($owner->fresh());

        $this->getJson("/api/companies/{$company->id}/seller-applications")
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CrmDeal;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRM Deals write scope (2026-06-12, roadmap §4 Pipeline drag-drop).
 *
 * Deal mutations were previously platform-admin-only (the middleware blocked
 * every tenant write, and the controller had NO company check of its own).
 * Opening PATCH/POST/DELETE crm/deals to operators therefore required adding
 * the same guards leads use: canWriteCompanyRows + Layer-B row ownership.
 */
class CrmDealWriteScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'company_admin', 'company_viewer'] as $r) {
            Role::query()->firstOrCreate(['name' => $r]);
        }
    }

    private function roleId(string $name): int
    {
        return (int) Role::query()->where('name', $name)->value('id');
    }

    private function company(string $name): Company
    {
        return Company::query()->create(['name' => $name, 'type' => 'operator']);
    }

    private function makeOwner(Company $company): User
    {
        $owner = User::factory()->create();
        $owner->companies()->attach($company->id, ['role_id' => $this->roleId('company_admin')]);

        return $owner;
    }

    private function makeEmployee(Company $company): User
    {
        $emp = User::factory()->create();
        $emp->companies()->attach($company->id, ['role_id' => $this->roleId('company_viewer')]);

        return $emp;
    }

    private function deal(Company $company, User $owner, string $stage = 'new'): CrmDeal
    {
        return CrmDeal::query()->create([
            'title' => 'Deal '.str()->uuid(),
            'company_id' => $company->id,
            'owner_user_id' => $owner->id,
            'stage' => $stage,
            'value_amount' => 100,
            'currency' => 'USD',
        ]);
    }

    public function test_operator_owner_moves_own_deal_to_negotiation(): void
    {
        $company = $this->company('Drag Co');
        $owner = $this->makeOwner($company);
        $deal = $this->deal($company, $owner, 'proposal');

        Sanctum::actingAs($owner->fresh());

        $this->patchJson("/api/platform-admin/crm/deals/{$deal->id}", ['stage' => 'negotiation'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'negotiation');

        $this->assertDatabaseHas('crm_deals', ['id' => $deal->id, 'stage' => 'negotiation']);
    }

    public function test_operator_cannot_touch_another_companys_deal(): void
    {
        $mine = $this->company('Mine');
        $other = $this->company('Other');
        $me = $this->makeOwner($mine);
        $foreignOwner = $this->makeOwner($other);
        $foreignDeal = $this->deal($other, $foreignOwner);

        Sanctum::actingAs($me->fresh());

        $this->patchJson("/api/platform-admin/crm/deals/{$foreignDeal->id}", ['stage' => 'won'])
            ->assertForbidden();
        $this->deleteJson("/api/platform-admin/crm/deals/{$foreignDeal->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('crm_deals', ['id' => $foreignDeal->id, 'stage' => 'new', 'deleted_at' => null]);
    }

    public function test_row_scoped_employee_moves_only_own_deals(): void
    {
        $company = $this->company('Row Co');
        $owner = $this->makeOwner($company);
        $emp = $this->makeEmployee($company);
        $ownDeal = $this->deal($company, $emp);
        $colleagueDeal = $this->deal($company, $owner);

        Sanctum::actingAs($emp->fresh());

        $this->patchJson("/api/platform-admin/crm/deals/{$ownDeal->id}", ['stage' => 'qualified'])
            ->assertOk();
        $this->patchJson("/api/platform-admin/crm/deals/{$colleagueDeal->id}", ['stage' => 'qualified'])
            ->assertForbidden();
    }

    public function test_operator_cannot_create_deal_for_another_company(): void
    {
        $mine = $this->company('Mine');
        $other = $this->company('Other');
        $me = $this->makeOwner($mine);

        Sanctum::actingAs($me->fresh());

        $this->postJson('/api/platform-admin/crm/deals', [
            'title' => 'Sneaky deal',
            'company_id' => $other->id,
        ])->assertForbidden();

        // Default path: lands in the caller's own company, owner stamped.
        $this->postJson('/api/platform-admin/crm/deals', ['title' => 'My deal'])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $mine->id);

        $this->assertDatabaseHas('crm_deals', [
            'title' => 'My deal',
            'company_id' => $mine->id,
            'owner_user_id' => $me->id,
        ]);
    }

    public function test_owner_reassignment_must_stay_on_the_team(): void
    {
        $company = $this->company('Assign Co');
        $owner = $this->makeOwner($company);
        $deal = $this->deal($company, $owner);
        $outsider = User::factory()->create();

        Sanctum::actingAs($owner->fresh());

        $this->patchJson("/api/platform-admin/crm/deals/{$deal->id}", ['owner_user_id' => $outsider->id])
            ->assertStatus(422);

        $emp = $this->makeEmployee($company);
        $this->patchJson("/api/platform-admin/crm/deals/{$deal->id}", ['owner_user_id' => $emp->id])
            ->assertOk();
    }

    public function test_super_admin_still_edits_any_deal(): void
    {
        $company = $this->company('Any Co');
        $owner = $this->makeOwner($company);
        $deal = $this->deal($company, $owner);

        $this->seed(RbacBootstrapSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'admin@zulu.local')->firstOrFail());

        $this->patchJson("/api/platform-admin/crm/deals/{$deal->id}", ['stage' => 'won'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'won');
    }
}

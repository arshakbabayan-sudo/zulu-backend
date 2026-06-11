<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRM Leads (2026-06-12, roadmap §4) — tenant scoping, Layer-B row ownership,
 * the convert-to-deal flow and the KPI stats endpoint.
 */
class CrmLeadsTest extends TestCase
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

    public function test_owner_creates_lead_stamped_with_own_company(): void
    {
        $company = Company::query()->create(['name' => 'Lead Co', 'type' => 'operator']);
        $owner = $this->makeOwner($company);

        Sanctum::actingAs($owner->fresh());

        $this->postJson('/api/platform-admin/crm/leads', [
            'name' => 'Anna Q',
            'email' => 'anna@example.com',
            'source' => 'website',
            'interest' => 'package',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Anna Q')
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.company_id', $company->id);

        $this->assertDatabaseHas('crm_leads', [
            'name' => 'Anna Q',
            'company_id' => $company->id,
            'owner_user_id' => $owner->id,
        ]);
    }

    public function test_owner_cannot_create_lead_for_another_company(): void
    {
        $mine = Company::query()->create(['name' => 'Mine', 'type' => 'operator']);
        $other = Company::query()->create(['name' => 'Other', 'type' => 'operator']);
        $owner = $this->makeOwner($mine);

        Sanctum::actingAs($owner->fresh());

        $this->postJson('/api/platform-admin/crm/leads', [
            'name' => 'Sneaky',
            'company_id' => $other->id,
        ])->assertForbidden();
    }

    public function test_list_is_company_scoped_and_super_sees_all(): void
    {
        $a = Company::query()->create(['name' => 'A', 'type' => 'operator']);
        $b = Company::query()->create(['name' => 'B', 'type' => 'operator']);
        $ownerA = $this->makeOwner($a);
        CrmLead::query()->create(['name' => 'Lead A', 'company_id' => $a->id]);
        CrmLead::query()->create(['name' => 'Lead B', 'company_id' => $b->id]);

        Sanctum::actingAs($ownerA->fresh());
        $res = $this->getJson('/api/platform-admin/crm/leads')->assertOk()->json();
        $this->assertCount(1, $res['data']);
        $this->assertSame('Lead A', $res['data'][0]['name']);

        $super = User::factory()->create();
        $super->companies()->attach($a->id, ['role_id' => $this->roleId('super_admin')]);
        Sanctum::actingAs($super->fresh());
        $res = $this->getJson('/api/platform-admin/crm/leads')->assertOk()->json();
        $this->assertCount(2, $res['data']);
    }

    public function test_plain_employee_is_row_scoped_and_cannot_touch_peer_leads(): void
    {
        $company = Company::query()->create(['name' => 'Row Co', 'type' => 'operator']);
        $empA = $this->makeEmployee($company);
        $empB = $this->makeEmployee($company);

        // Employee A creates a lead — owner is forced to themselves even if
        // they try to assign a colleague.
        Sanctum::actingAs($empA->fresh());
        $created = $this->postJson('/api/platform-admin/crm/leads', [
            'name' => 'Own Lead',
            'owner_user_id' => $empB->id,
        ])->assertCreated()->json();
        $this->assertSame($empA->id, $created['data']['owner']['id']);

        // Employee B sees nothing (row scope) and cannot edit A's lead.
        Sanctum::actingAs($empB->fresh());
        $res = $this->getJson('/api/platform-admin/crm/leads')->assertOk()->json();
        $this->assertCount(0, $res['data']);
        $this->patchJson('/api/platform-admin/crm/leads/'.$created['data']['id'], ['status' => 'contacted'])
            ->assertForbidden();

        // The company owner sees the whole company's leads.
        $owner = $this->makeOwner($company);
        Sanctum::actingAs($owner->fresh());
        $res = $this->getJson('/api/platform-admin/crm/leads')->assertOk()->json();
        $this->assertCount(1, $res['data']);
    }

    public function test_cross_company_lead_update_is_forbidden(): void
    {
        $mine = Company::query()->create(['name' => 'Mine2', 'type' => 'operator']);
        $other = Company::query()->create(['name' => 'Other2', 'type' => 'operator']);
        $owner = $this->makeOwner($mine);
        $foreign = CrmLead::query()->create(['name' => 'Foreign', 'company_id' => $other->id]);

        Sanctum::actingAs($owner->fresh());

        $this->patchJson('/api/platform-admin/crm/leads/'.$foreign->id, ['status' => 'qualified'])
            ->assertForbidden();
        $this->deleteJson('/api/platform-admin/crm/leads/'.$foreign->id)->assertForbidden();
    }

    public function test_convert_creates_deal_and_freezes_lead(): void
    {
        $company = Company::query()->create(['name' => 'Conv Co', 'type' => 'operator']);
        $owner = $this->makeOwner($company);
        $lead = CrmLead::query()->create([
            'name' => 'Davit G',
            'company_id' => $company->id,
            'owner_user_id' => $owner->id,
            'source' => 'referral',
            'interest' => 'hotel',
            'status' => 'qualified',
            'notes' => 'Wants July',
        ]);

        Sanctum::actingAs($owner->fresh());

        $res = $this->postJson("/api/platform-admin/crm/leads/{$lead->id}/convert", [
            'value_amount' => 1200,
            'currency' => 'USD',
        ])->assertCreated()->json();

        $dealId = $res['data']['deal_id'];
        $this->assertSame('converted', $res['data']['lead']['status']);
        $this->assertSame($dealId, $res['data']['lead']['converted_deal_id']);

        $deal = CrmDeal::query()->findOrFail($dealId);
        $this->assertSame('hotel', $deal->service_type);
        $this->assertSame('referral', $deal->source);
        $this->assertSame($company->id, $deal->company_id);
        $this->assertSame($owner->id, $deal->owner_user_id);
        $this->assertSame(1200.0, (float) $deal->value_amount);

        // A second convert is refused; status cannot be set back by PATCH.
        $this->postJson("/api/platform-admin/crm/leads/{$lead->id}/convert")
            ->assertStatus(422);
        $this->patchJson("/api/platform-admin/crm/leads/{$lead->id}", ['status' => 'new'])
            ->assertOk()
            ->assertJsonPath('data.status', 'converted');
    }

    public function test_owner_assignment_must_be_company_member(): void
    {
        $mine = Company::query()->create(['name' => 'Team Co', 'type' => 'operator']);
        $other = Company::query()->create(['name' => 'Foreign Co', 'type' => 'operator']);
        $owner = $this->makeOwner($mine);
        $outsider = $this->makeEmployee($other);

        Sanctum::actingAs($owner->fresh());

        // Assigning a lead to someone outside the company makes it invisible
        // to every row-scoped view — refuse with a clean 422.
        $this->postJson('/api/platform-admin/crm/leads', [
            'name' => 'Misassigned',
            'owner_user_id' => $outsider->id,
        ])->assertStatus(422);

        $lead = CrmLead::query()->create(['name' => 'Mine', 'company_id' => $mine->id, 'owner_user_id' => $owner->id]);
        $this->patchJson("/api/platform-admin/crm/leads/{$lead->id}", ['owner_user_id' => $outsider->id])
            ->assertStatus(422);
    }

    public function test_patch_with_explicit_null_status_is_ignored(): void
    {
        $company = Company::query()->create(['name' => 'Null Co', 'type' => 'operator']);
        $owner = $this->makeOwner($company);
        $lead = CrmLead::query()->create(['name' => 'N', 'company_id' => $company->id, 'status' => 'contacted']);

        Sanctum::actingAs($owner->fresh());

        // status is NOT NULL in the schema — an explicit JSON null must not 500.
        $this->patchJson("/api/platform-admin/crm/leads/{$lead->id}", ['status' => null, 'notes' => 'kept'])
            ->assertOk()
            ->assertJsonPath('data.status', 'contacted')
            ->assertJsonPath('data.notes', 'kept');
    }

    public function test_status_converted_is_not_directly_settable(): void
    {
        $company = Company::query()->create(['name' => 'Val Co', 'type' => 'operator']);
        $owner = $this->makeOwner($company);

        Sanctum::actingAs($owner->fresh());

        $this->postJson('/api/platform-admin/crm/leads', [
            'name' => 'X',
            'status' => 'converted',
        ])->assertStatus(422);
    }

    public function test_leads_stats_shape_and_values(): void
    {
        $company = Company::query()->create(['name' => 'Stat Co', 'type' => 'operator']);
        $owner = $this->makeOwner($company);
        CrmLead::query()->create(['name' => 'L1', 'company_id' => $company->id, 'status' => 'qualified', 'owner_user_id' => $owner->id]);
        CrmLead::query()->create(['name' => 'L2', 'company_id' => $company->id, 'status' => 'converted']);
        CrmLead::query()->create(['name' => 'L3', 'company_id' => $company->id, 'status' => 'new']);

        Sanctum::actingAs($owner->fresh());

        $res = $this->getJson('/api/platform-admin/crm/leads/stats')->assertOk()->json();
        $this->assertSame(3, $res['data']['new_7d']);
        $this->assertSame(1, $res['data']['qualified']);
        $this->assertSame(2, $res['data']['unassigned']);
        $this->assertSame(33, $res['data']['conversion_rate']);
    }
}

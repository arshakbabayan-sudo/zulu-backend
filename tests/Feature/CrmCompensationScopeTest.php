<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Setting an employee's CRM compensation (PUT platform-admin/crm/team/{user}/
 * compensation) must be reachable by the company OWNER for their OWN employees,
 * not only super-admin. Regression guard for the 2026-06-09 fix: the write was
 * stuck behind the platform-admin deny-all middleware, so operators/agents got
 * 403 on Save in the CRM → Team compensation drawer.
 */
class CrmCompensationScopeTest extends TestCase
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

    private function payload(int $companyId): array
    {
        return [
            'company_id' => $companyId,
            'model' => 'fixed',
            'base_amount' => 500,
            'commission_percent' => 0,
            'currency' => 'USD',
        ];
    }

    public function test_operator_owner_can_set_own_employee_pay(): void
    {
        $company = Company::query()->create(['name' => 'Owner Co', 'type' => 'operator']);
        $owner = User::factory()->create();
        $owner->companies()->attach($company->id, ['role_id' => $this->roleId('company_admin')]);
        $employee = User::factory()->create();
        $employee->companies()->attach($company->id, ['role_id' => $this->roleId('company_viewer')]);

        Sanctum::actingAs($owner->fresh());

        $this->putJson("/api/platform-admin/crm/team/{$employee->id}/compensation", $this->payload($company->id))
            ->assertOk()
            ->assertJsonPath('data.model', 'fixed')
            ->assertJsonPath('data.user_id', $employee->id);

        $this->assertDatabaseHas('crm_employee_compensation', [
            'user_id' => $employee->id,
            'company_id' => $company->id,
            'model' => 'fixed',
        ]);
    }

    public function test_operator_cannot_set_pay_for_another_company(): void
    {
        $mine = Company::query()->create(['name' => 'Mine', 'type' => 'operator']);
        $other = Company::query()->create(['name' => 'Other', 'type' => 'operator']);
        $owner = User::factory()->create();
        $owner->companies()->attach($mine->id, ['role_id' => $this->roleId('company_admin')]);
        $otherEmployee = User::factory()->create();
        $otherEmployee->companies()->attach($other->id, ['role_id' => $this->roleId('company_viewer')]);

        Sanctum::actingAs($owner->fresh());

        // The owner of "Mine" cannot manage "Other" → forbidden.
        $this->putJson("/api/platform-admin/crm/team/{$otherEmployee->id}/compensation", $this->payload($other->id))
            ->assertForbidden();
    }

    public function test_plain_employee_cannot_set_pay(): void
    {
        $company = Company::query()->create(['name' => 'Co', 'type' => 'operator']);
        $viewer = User::factory()->create();
        $viewer->companies()->attach($company->id, ['role_id' => $this->roleId('company_viewer')]);
        $peer = User::factory()->create();
        $peer->companies()->attach($company->id, ['role_id' => $this->roleId('company_viewer')]);

        Sanctum::actingAs($viewer->fresh());

        // A non-owner employee cannot set anyone's pay, even within their company.
        $this->putJson("/api/platform-admin/crm/team/{$peer->id}/compensation", $this->payload($company->id))
            ->assertForbidden();
    }

    public function test_super_admin_can_still_set_pay(): void
    {
        $company = Company::query()->create(['name' => 'SA Co', 'type' => 'operator']);
        $employee = User::factory()->create();
        $employee->companies()->attach($company->id, ['role_id' => $this->roleId('company_viewer')]);
        $super = User::factory()->create();
        $super->companies()->attach($company->id, ['role_id' => $this->roleId('super_admin')]);

        Sanctum::actingAs($super->fresh());

        $this->putJson("/api/platform-admin/crm/team/{$employee->id}/compensation", $this->payload($company->id))
            ->assertOk();
    }
}

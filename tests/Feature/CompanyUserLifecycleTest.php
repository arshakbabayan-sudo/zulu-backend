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
 * Roadmap 10.06 §7 — employee lifecycle: deactivate / reactivate / remove.
 *
 * deactivate existed but had no rank ceiling (a company_operator could suspend
 * the owner) and no lockout protection; reactivate + remove are new. Remove
 * detaches the membership only — the user ACCOUNT and payroll history are kept.
 */
class CompanyUserLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Role> */
    private array $roles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->roles = [
            'super_admin' => Role::updateOrCreate(['name' => 'super_admin'], ['scope' => Role::SCOPE_PLATFORM]),
            'company_admin' => Role::updateOrCreate(['name' => 'company_admin'], ['scope' => Role::SCOPE_COMPANY]),
            'company_operator' => Role::updateOrCreate(['name' => 'company_operator'], ['scope' => Role::SCOPE_COMPANY]),
            'company_viewer' => Role::updateOrCreate(['name' => 'company_viewer'], ['scope' => Role::SCOPE_COMPANY]),
        ];
    }

    private function company(string $name = 'Lifecycle Co'): Company
    {
        return Company::create(['name' => $name.' '.uniqid(), 'type' => 'operator']);
    }

    private function member(Company $company, string $roleKey, string $status = 'active'): User
    {
        $user = User::factory()->create(['status' => $status]);
        UserCompany::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $this->roles[$roleKey]->id,
        ]);

        return $user;
    }

    public function test_owner_can_deactivate_and_reactivate_employee(): void
    {
        $co = $this->company();
        $owner = $this->member($co, 'company_admin');
        $employee = $this->member($co, 'company_viewer');

        Sanctum::actingAs($owner->fresh());

        $this->patchJson("/api/companies/{$co->id}/users/{$employee->id}/deactivate")->assertOk();
        $this->assertSame('inactive', $employee->fresh()->status);

        $this->patchJson("/api/companies/{$co->id}/users/{$employee->id}/reactivate")->assertOk();
        $this->assertSame('active', $employee->fresh()->status);
    }

    public function test_lower_rank_cannot_deactivate_owner(): void
    {
        $co = $this->company();
        $owner = $this->member($co, 'company_admin');
        $operator = $this->member($co, 'company_operator');

        Sanctum::actingAs($operator->fresh());

        $this->patchJson("/api/companies/{$co->id}/users/{$owner->id}/deactivate")->assertForbidden();
        $this->assertSame('active', $owner->fresh()->status);
    }

    public function test_cannot_deactivate_last_active_owner(): void
    {
        $co = $this->company();
        // Caller is an owner whose own account is suspended — the target is the
        // company's only remaining ACTIVE owner, so the action must be blocked.
        $suspendedOwner = $this->member($co, 'company_admin', 'inactive');
        $lastActiveOwner = $this->member($co, 'company_admin');

        Sanctum::actingAs($suspendedOwner->fresh());

        $this->patchJson("/api/companies/{$co->id}/users/{$lastActiveOwner->id}/deactivate")
            ->assertStatus(422);
        $this->assertSame('active', $lastActiveOwner->fresh()->status);
    }

    public function test_super_admin_bypasses_last_owner_protection(): void
    {
        $co = $this->company();
        $soleOwner = $this->member($co, 'company_admin');

        $platformCo = $this->company('ZULU HQ');
        $super = $this->member($platformCo, 'super_admin');

        Sanctum::actingAs($super->fresh());

        $this->patchJson("/api/companies/{$co->id}/users/{$soleOwner->id}/deactivate")->assertOk();
        $this->assertSame('inactive', $soleOwner->fresh()->status);
    }

    public function test_owner_can_remove_employee_membership_only(): void
    {
        $co = $this->company();
        $owner = $this->member($co, 'company_admin');
        $employee = $this->member($co, 'company_viewer');

        Sanctum::actingAs($owner->fresh());

        $this->deleteJson("/api/companies/{$co->id}/users/{$employee->id}")->assertOk();

        $this->assertDatabaseMissing('user_company', [
            'company_id' => $co->id,
            'user_id' => $employee->id,
        ]);
        // Archive over erase: the account itself survives.
        $this->assertDatabaseHas('users', ['id' => $employee->id]);
    }

    public function test_cannot_remove_self(): void
    {
        $co = $this->company();
        $owner = $this->member($co, 'company_admin');

        Sanctum::actingAs($owner->fresh());

        $this->deleteJson("/api/companies/{$co->id}/users/{$owner->id}")->assertStatus(422);
    }

    public function test_cannot_remove_last_active_owner(): void
    {
        $co = $this->company();
        $suspendedOwner = $this->member($co, 'company_admin', 'inactive');
        $lastActiveOwner = $this->member($co, 'company_admin');

        Sanctum::actingAs($suspendedOwner->fresh());

        $this->deleteJson("/api/companies/{$co->id}/users/{$lastActiveOwner->id}")->assertStatus(422);
        $this->assertDatabaseHas('user_company', [
            'company_id' => $co->id,
            'user_id' => $lastActiveOwner->id,
        ]);
    }

    public function test_manager_of_another_company_is_forbidden(): void
    {
        $mine = $this->company('Mine');
        $other = $this->company('Other');
        $outsideOwner = $this->member($other, 'company_admin');
        $employee = $this->member($mine, 'company_viewer');

        Sanctum::actingAs($outsideOwner->fresh());

        $this->patchJson("/api/companies/{$mine->id}/users/{$employee->id}/deactivate")->assertForbidden();
        $this->patchJson("/api/companies/{$mine->id}/users/{$employee->id}/reactivate")->assertForbidden();
        $this->deleteJson("/api/companies/{$mine->id}/users/{$employee->id}")->assertForbidden();
    }
}

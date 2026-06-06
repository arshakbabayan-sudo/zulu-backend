<?php

namespace Tests\Feature;

use App\Models\CompanyApplication;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompany;
use App\Services\Approvals\CompanyApplicationApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the owner-role assignment on company-application approval (Block 2.2).
 *
 * The owner of a newly-approved company must become company_admin (rank 3 in
 * CompanyRbacController::ROLE_RANK), NOT operator_admin (rank 2). If the owner
 * were operator_admin they'd be rank-equal to the staff they later add, and the
 * "can this caller grant this role" ceiling in the permissions drawer breaks.
 */
class CompanyApplicationApprovalOwnerRoleTest extends TestCase
{
    use RefreshDatabase;

    private function approveSvc(): CompanyApplicationApprovalService
    {
        return app(CompanyApplicationApprovalService::class);
    }

    private function reviewer(): User
    {
        return User::query()->create([
            'name' => 'Reviewer',
            'email' => 'reviewer-'.uniqid().'@test.local',
            'password' => 'secret123',
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function pendingApplication(array $overrides = []): CompanyApplication
    {
        return CompanyApplication::query()->create(array_merge([
            'company_name' => 'Acme Travel '.uniqid(),
            'company_type' => CompanyApplication::TYPE_OPERATOR,
            'business_email' => 'owner-'.uniqid().'@test.local',
            'legal_address' => '1 Legal St',
            'actual_address' => '1 Actual St',
            'country' => 'Armenia',
            'city' => 'Yerevan',
            'phone' => '+37400000000',
            'tax_id' => '0000'.random_int(1000, 9999),
            'contact_person' => 'Owner Person',
            'position' => 'CEO',
            'status' => CompanyApplication::STATUS_PENDING,
        ], $overrides));
    }

    /** When BOTH roles exist, the owner must get company_admin, not operator_admin. */
    public function test_owner_gets_company_admin_when_both_roles_exist(): void
    {
        Role::query()->firstOrCreate(['name' => 'operator_admin']);
        Role::query()->firstOrCreate(['name' => 'company_admin']);

        $app = $this->pendingApplication();
        $result = $this->approveSvc()->approve($app, $this->reviewer());

        $membership = UserCompany::query()
            ->where('user_id', $result['user']->id)
            ->where('company_id', $result['company']->id)
            ->with('role')
            ->first();

        $this->assertNotNull($membership);
        $this->assertSame('company_admin', $membership->role->name);
    }

    /** Pre-registered (Phase-8) owner: attaches to existing user, still company_admin. */
    public function test_preregistered_owner_gets_company_admin(): void
    {
        Role::query()->firstOrCreate(['name' => 'operator_admin']);
        Role::query()->firstOrCreate(['name' => 'company_admin']);

        $owner = User::query()->create([
            'name' => 'Pre Reg',
            'email' => 'prereg-'.uniqid().'@test.local',
            'password' => 'secret123',
            'status' => User::STATUS_ACTIVE,
            'intended_role' => 'operator',
        ]);

        $app = $this->pendingApplication(['user_id' => $owner->id]);
        $result = $this->approveSvc()->approve($app, $this->reviewer());

        $this->assertSame($owner->id, $result['user']->id);

        $membership = UserCompany::query()
            ->where('user_id', $owner->id)
            ->where('company_id', $result['company']->id)
            ->with('role')
            ->first();

        $this->assertNotNull($membership);
        $this->assertSame('company_admin', $membership->role->name);
    }

    /**
     * "Become a partner" footer flow: a logged-in B2C user submits without a
     * bearer token (public submit), so application.user_id is null but the
     * business_email matches an existing account. Approval must ATTACH the
     * company to that account, not throw "user already exists" and not mint a
     * duplicate. Regression guard for the bug Arshak hit 2026-06-02.
     */
    public function test_attaches_to_existing_user_when_email_matches_and_user_id_null(): void
    {
        Role::query()->firstOrCreate(['name' => 'operator_admin']);
        Role::query()->firstOrCreate(['name' => 'company_admin']);

        $b2c = User::query()->create([
            'name' => 'Existing B2C',
            'email' => 'b2c-'.uniqid().'@test.local',
            'password' => 'secret123',
            'status' => User::STATUS_ACTIVE,
        ]);

        // user_id intentionally NULL; business_email points at the B2C account.
        $app = $this->pendingApplication([
            'user_id' => null,
            'business_email' => $b2c->email,
        ]);

        $result = $this->approveSvc()->approve($app, $this->reviewer());

        // Same account, not a freshly minted duplicate.
        $this->assertSame($b2c->id, $result['user']->id);
        $this->assertSame(
            1,
            User::query()->where('email', $b2c->email)->count(),
            'Approval must not create a second user with the same email.'
        );
        $this->assertSame('', $result['temporary_password'], 'No temp password when attaching to an existing user.');

        $membership = UserCompany::query()
            ->where('user_id', $b2c->id)
            ->where('company_id', $result['company']->id)
            ->with('role')
            ->first();

        $this->assertNotNull($membership);
        $this->assertSame('company_admin', $membership->role->name);
    }

    /** Fallback: if company_admin row is absent, operator_admin is still accepted. */
    public function test_falls_back_to_operator_admin_when_company_admin_missing(): void
    {
        Role::query()->firstOrCreate(['name' => 'operator_admin']);

        $app = $this->pendingApplication();
        $result = $this->approveSvc()->approve($app, $this->reviewer());

        $membership = UserCompany::query()
            ->where('user_id', $result['user']->id)
            ->where('company_id', $result['company']->id)
            ->with('role')
            ->first();

        $this->assertNotNull($membership);
        $this->assertSame('operator_admin', $membership->role->name);
    }

    /** RBAC #2 Part Բ — an AGENCY application's owner gets the `agent` role (not company_admin). */
    public function test_agency_owner_gets_agent_role(): void
    {
        Role::query()->firstOrCreate(['name' => 'company_admin']);
        Role::query()->firstOrCreate(['name' => 'agent']);

        $app = $this->pendingApplication(['company_type' => CompanyApplication::TYPE_AGENT]);
        $result = $this->approveSvc()->approve($app, $this->reviewer());

        $membership = UserCompany::query()
            ->where('user_id', $result['user']->id)
            ->where('company_id', $result['company']->id)
            ->with('role')
            ->first();

        $this->assertNotNull($membership);
        $this->assertSame('agent', $membership->role->name);
    }

    /** RBAC #2 Part Բ — operator applications are unaffected: owner still gets company_admin. */
    public function test_operator_owner_still_gets_company_admin_with_agent_role_present(): void
    {
        Role::query()->firstOrCreate(['name' => 'company_admin']);
        Role::query()->firstOrCreate(['name' => 'agent']);

        $app = $this->pendingApplication(['company_type' => CompanyApplication::TYPE_OPERATOR]);
        $result = $this->approveSvc()->approve($app, $this->reviewer());

        $membership = UserCompany::query()
            ->where('user_id', $result['user']->id)
            ->where('company_id', $result['company']->id)
            ->with('role')
            ->first();

        $this->assertNotNull($membership);
        $this->assertSame('company_admin', $membership->role->name);
    }

    /** RBAC #2 Part Բ — if the `agent` role row is missing, an agency owner falls back to the operator role. */
    public function test_agency_owner_falls_back_when_agent_role_missing(): void
    {
        Role::query()->firstOrCreate(['name' => 'company_admin']);
        // intentionally no `agent` role

        $app = $this->pendingApplication(['company_type' => CompanyApplication::TYPE_AGENT]);
        $result = $this->approveSvc()->approve($app, $this->reviewer());

        $membership = UserCompany::query()
            ->where('user_id', $result['user']->id)
            ->where('company_id', $result['company']->id)
            ->with('role')
            ->first();

        $this->assertNotNull($membership);
        $this->assertSame('company_admin', $membership->role->name);
    }
}

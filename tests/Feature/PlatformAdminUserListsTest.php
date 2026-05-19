<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7.1 / 7.5 / 7.8 feature tests — platform-admin user list endpoints.
 *
 * Routes (all under platform-admin group, sanctum-auth):
 *   GET /api/platform-admin/users              listUsers (Phase 7.5 staff
 *                                              filter via `with_companies=1`)
 *   GET /api/platform-admin/customers          listCustomers (Phase 7.1 B2C)
 *   GET /api/platform-admin/unverified-accounts listUnverifiedAccounts (Phase 7.8)
 *
 * Acceptance criteria:
 *   - All three require platform_admin role; staff/guests are 403
 *   - listCustomers includes only users with zero memberships
 *   - listUsers + with_companies=1 includes only users WITH memberships
 *   - listUnverifiedAccounts includes pending status OR null email_verified_at
 */
class PlatformAdminUserListsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_is_rejected_on_all_three(): void
    {
        $this->getJson('/api/platform-admin/users')->assertStatus(401);
        $this->getJson('/api/platform-admin/customers')->assertStatus(401);
        $this->getJson('/api/platform-admin/unverified-accounts')->assertStatus(401);
    }

    public function test_non_platform_admin_is_forbidden(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/platform-admin/users')->assertStatus(403);
        $this->getJson('/api/platform-admin/customers')->assertStatus(403);
        $this->getJson('/api/platform-admin/unverified-accounts')->assertStatus(403);
    }

    public function test_list_customers_excludes_users_with_memberships(): void
    {
        $admin = $this->makePlatformAdmin();
        $company = $this->makeCompany();

        // B2C user — no companies.
        $b2c = $this->makeUser('b2c-user@example.test');

        // Staff user — has membership.
        $staff = $this->makeUser('staff-user@example.test');
        $role = Role::query()->firstOrCreate(['name' => 'company_admin']);
        $staff->companies()->attach($company->id, ['role_id' => $role->id]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/platform-admin/customers');
        $response->assertOk();

        $emails = collect($response->json('data'))->pluck('email')->all();
        $this->assertContains('b2c-user@example.test', $emails);
        $this->assertNotContains('staff-user@example.test', $emails);
    }

    public function test_list_customers_supports_search_across_name_email_phone(): void
    {
        $admin = $this->makePlatformAdmin();

        User::query()->create([
            'name' => 'Mariam Searchable',
            'email' => 'mariam@example.test',
            'password' => bcrypt('p'),
            'status' => User::STATUS_ACTIVE,
        ]);
        User::query()->create([
            'name' => 'Other',
            'email' => 'other@example.test',
            'password' => bcrypt('p'),
            'status' => User::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/platform-admin/customers?search=Mariam');
        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Mariam Searchable', $names);
        $this->assertNotContains('Other', $names);
    }

    public function test_list_users_with_companies_filter_returns_only_staff(): void
    {
        $admin = $this->makePlatformAdmin();
        $company = $this->makeCompany();

        $b2c = $this->makeUser('b2c-only@example.test');
        $staff = $this->makeUser('staff-only@example.test');
        $role = Role::query()->firstOrCreate(['name' => 'company_admin']);
        $staff->companies()->attach($company->id, ['role_id' => $role->id]);

        Sanctum::actingAs($admin);

        $withCompanies = $this->getJson('/api/platform-admin/users?with_companies=1');
        $withCompanies->assertOk();
        $emails = collect($withCompanies->json('data'))->pluck('email')->all();
        $this->assertContains('staff-only@example.test', $emails);
        $this->assertNotContains('b2c-only@example.test', $emails);

        // Without the filter, both are visible.
        $all = $this->getJson('/api/platform-admin/users');
        $allEmails = collect($all->json('data'))->pluck('email')->all();
        $this->assertContains('staff-only@example.test', $allEmails);
        $this->assertContains('b2c-only@example.test', $allEmails);
    }

    public function test_list_unverified_accounts_includes_pending_status_and_null_verified(): void
    {
        $admin = $this->makePlatformAdmin();

        // Pending status (email_verified_at set so only status triggers the
        // unverified bucket). User model doesn't include email_verified_at in
        // $fillable, so we have to forceFill it after create.
        $pending = User::query()->create([
            'name' => 'Pending Status',
            'email' => 'pending@example.test',
            'password' => bcrypt('p'),
            'status' => 'pending',
        ]);
        $pending->forceFill(['email_verified_at' => now()])->save();

        // Null email_verified_at — appears via OR branch.
        User::query()->create([
            'name' => 'No Verification',
            'email' => 'no-verify@example.test',
            'password' => bcrypt('p'),
            'status' => User::STATUS_ACTIVE,
        ]); // email_verified_at defaults to null

        // Fully verified active — should NOT appear.
        $healthy = User::query()->create([
            'name' => 'Healthy',
            'email' => 'healthy@example.test',
            'password' => bcrypt('p'),
            'status' => User::STATUS_ACTIVE,
        ]);
        $healthy->forceFill(['email_verified_at' => now()])->save();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/platform-admin/unverified-accounts');
        $response->assertOk();
        $emails = collect($response->json('data'))->pluck('email')->all();
        $this->assertContains('pending@example.test', $emails);
        $this->assertContains('no-verify@example.test', $emails);
        $this->assertNotContains('healthy@example.test', $emails);
    }

    public function test_unverified_accounts_supports_search_filter(): void
    {
        $admin = $this->makePlatformAdmin();

        User::query()->create([
            'name' => 'Findable Name',
            'email' => 'findable@example.test',
            'password' => bcrypt('p'),
            'status' => 'pending',
        ]);
        User::query()->create([
            'name' => 'Other Pending',
            'email' => 'other-pending@example.test',
            'password' => bcrypt('p'),
            'status' => 'pending',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/platform-admin/unverified-accounts?search=Findable');
        $response->assertOk();
        $emails = collect($response->json('data'))->pluck('email')->all();
        $this->assertContains('findable@example.test', $emails);
        $this->assertNotContains('other-pending@example.test', $emails);
    }

    private function makeUser(?string $email = null): User
    {
        return User::query()->create([
            'name' => 'Phase 7.x Test',
            'email' => $email ?? 'p7x-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Phase 7.x '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function makePlatformAdmin(): User
    {
        $user = $this->makeUser();
        $role = Role::query()->firstOrCreate(['name' => 'platform_admin']);
        $platform = $this->makeCompany();
        $user->companies()->attach($platform->id, ['role_id' => $role->id]);

        return $user;
    }
}

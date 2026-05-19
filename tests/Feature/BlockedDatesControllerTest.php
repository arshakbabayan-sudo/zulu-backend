<?php

namespace Tests\Feature;

use App\Models\BlockedDate;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7.2 feature tests — operator blocked-date CRUD endpoints.
 *
 * Routes:
 *   GET    /api/blocked-dates
 *   POST   /api/blocked-dates
 *   DELETE /api/blocked-dates/{id}
 *
 * The list view is scoped to the user's companies for non-super-admins.
 * Super admins see everything. Delete enforces the same boundary.
 */
class BlockedDatesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_is_rejected(): void
    {
        $this->getJson('/api/blocked-dates')->assertStatus(401);
    }

    public function test_index_returns_company_scoped_rows_only(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $userA = $this->makeUserForCompany($companyA);

        BlockedDate::query()->create([
            'company_id' => $companyA->id,
            'item_type' => 'hotel',
            'blocked_from' => '2026-06-01',
            'blocked_to' => '2026-06-07',
            'reason' => 'mine',
            'created_by_user_id' => $userA->id,
        ]);
        BlockedDate::query()->create([
            'company_id' => $companyB->id,
            'item_type' => 'hotel',
            'blocked_from' => '2026-06-10',
            'blocked_to' => '2026-06-15',
            'reason' => 'not mine',
            'created_by_user_id' => $userA->id,
        ]);

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/blocked-dates');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('mine', $response->json('data.0.reason'));
    }

    public function test_super_admin_sees_all_rows(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $admin = $this->makeUser();

        BlockedDate::query()->create([
            'company_id' => $companyA->id,
            'item_type' => 'hotel',
            'blocked_from' => '2026-06-01',
            'blocked_to' => '2026-06-07',
            'created_by_user_id' => $admin->id,
        ]);
        BlockedDate::query()->create([
            'company_id' => $companyB->id,
            'item_type' => 'hotel',
            'blocked_from' => '2026-06-10',
            'blocked_to' => '2026-06-15',
            'created_by_user_id' => $admin->id,
        ]);

        Sanctum::actingAs($this->attachSuperAdmin($admin));

        $response = $this->getJson('/api/blocked-dates');
        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_store_creates_blocked_date_for_user_company(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/blocked-dates', [
            'item_type' => 'hotel',
            'item_id' => 99,
            'blocked_from' => '2026-07-01',
            'blocked_to' => '2026-07-10',
            'reason' => 'maintenance',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.reason', 'maintenance');
        $this->assertDatabaseHas('blocked_dates', [
            'company_id' => $company->id,
            'item_type' => 'hotel',
            'item_id' => 99,
            'reason' => 'maintenance',
        ]);
    }

    public function test_store_rejects_inverted_range(): void
    {
        $company = $this->makeCompany();
        Sanctum::actingAs($this->makeUserForCompany($company));

        $this->postJson('/api/blocked-dates', [
            'item_type' => 'hotel',
            'blocked_from' => '2026-07-10',
            'blocked_to' => '2026-07-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['blocked_to']);
    }

    public function test_destroy_removes_row_owned_by_user_company(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        $row = BlockedDate::query()->create([
            'company_id' => $company->id,
            'item_type' => 'hotel',
            'blocked_from' => '2026-08-01',
            'blocked_to' => '2026-08-05',
            'created_by_user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/blocked-dates/{$row->id}")->assertOk();
        $this->assertDatabaseMissing('blocked_dates', ['id' => $row->id]);
    }

    public function test_destroy_rejects_cross_company_attempt(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $userA = $this->makeUserForCompany($companyA);
        $row = BlockedDate::query()->create([
            'company_id' => $companyB->id,
            'item_type' => 'hotel',
            'blocked_from' => '2026-08-01',
            'blocked_to' => '2026-08-05',
            'created_by_user_id' => $userA->id,
        ]);

        Sanctum::actingAs($userA);

        $this->deleteJson("/api/blocked-dates/{$row->id}")->assertStatus(403);
        $this->assertDatabaseHas('blocked_dates', ['id' => $row->id]);
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Phase 7.2 '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Phase 7.2 Test',
            'email' => 'p72-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function makeUserForCompany(Company $company): User
    {
        $user = $this->makeUser();
        $role = Role::query()->firstOrCreate(['name' => 'company_admin']);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }

    private function attachSuperAdmin(User $user): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'super_admin']);
        $platform = $this->makeCompany();
        $user->companies()->attach($platform->id, ['role_id' => $role->id]);

        return $user;
    }
}

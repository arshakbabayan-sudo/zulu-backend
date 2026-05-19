<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\TimeOffRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7.13 feature tests — time-off / non-service-hours workflow.
 *
 * Routes:
 *   GET   /api/time-off
 *   POST  /api/time-off
 *   PATCH /api/time-off/{id}/decide
 *
 * Workflow:
 *   employee posts → row lands in `pending`
 *   manager PATCHes /decide with status ∈ {approved, rejected, cancelled}
 *   the row stamps decided_by_user_id + decided_at
 */
class TimeOffControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_is_rejected(): void
    {
        $this->getJson('/api/time-off')->assertStatus(401);
        $this->postJson('/api/time-off', [])->assertStatus(401);
    }

    public function test_store_creates_pending_record_for_self_when_user_id_omitted(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUserForCompany($company);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/time-off', [
            'type' => 'vacation',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-10',
            'hours_total' => 80,
            'notes' => 'summer trip',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.user.id', $user->id);
        $response->assertJsonPath('data.type', 'vacation');
    }

    public function test_store_records_for_explicit_user_id(): void
    {
        $company = $this->makeCompany();
        $manager = $this->makeUserForCompany($company);
        $employee = $this->makeUser();
        Sanctum::actingAs($manager);

        $response = $this->postJson('/api/time-off', [
            'user_id' => $employee->id,
            'type' => 'sick',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-06-03',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.user.id', $employee->id);
    }

    public function test_store_rejects_inverted_range(): void
    {
        $company = $this->makeCompany();
        Sanctum::actingAs($this->makeUserForCompany($company));

        $this->postJson('/api/time-off', [
            'type' => 'vacation',
            'starts_on' => '2026-07-10',
            'ends_on' => '2026-07-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['ends_on']);
    }

    public function test_store_rejects_invalid_type(): void
    {
        $company = $this->makeCompany();
        Sanctum::actingAs($this->makeUserForCompany($company));

        $this->postJson('/api/time-off', [
            'type' => 'sabbatical',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-05',
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_store_requires_user_company(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/time-off', [
            'type' => 'vacation',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-05',
        ])->assertStatus(422)->assertJsonPath('message', 'No active company.');
    }

    public function test_decide_approves_and_stamps_decider(): void
    {
        $company = $this->makeCompany();
        $manager = $this->makeUserForCompany($company);
        $row = TimeOffRecord::query()->create([
            'company_id' => $company->id,
            'user_id' => $this->makeUser()->id,
            'type' => 'vacation',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-10',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($manager);

        $response = $this->patchJson("/api/time-off/{$row->id}/decide", [
            'status' => 'approved',
            'decision_notes' => 'Approved, enjoy!',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'approved');
        $response->assertJsonPath('data.decision_notes', 'Approved, enjoy!');
        $response->assertJsonPath('data.decided_by.id', $manager->id);
        $this->assertNotNull($response->json('data.decided_at'));
    }

    public function test_decide_rejects_invalid_status_value(): void
    {
        $company = $this->makeCompany();
        $manager = $this->makeUserForCompany($company);
        $row = TimeOffRecord::query()->create([
            'company_id' => $company->id,
            'user_id' => $this->makeUser()->id,
            'type' => 'vacation',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-10',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($manager);

        // 'pending' is not a valid decision (only approved/rejected/cancelled).
        $this->patchJson("/api/time-off/{$row->id}/decide", ['status' => 'pending'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_index_is_company_scoped(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $userA = $this->makeUserForCompany($companyA);
        TimeOffRecord::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $userA->id,
            'type' => 'vacation',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-05',
            'status' => 'pending',
        ]);
        TimeOffRecord::query()->create([
            'company_id' => $companyB->id,
            'user_id' => $this->makeUser()->id,
            'type' => 'vacation',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-05',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/time-off');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($companyA->id, $response->json('data.0.company_id'));
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Phase 7.13 '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Phase 7.13 Test',
            'email' => 'p713-'.str()->uuid().'@example.test',
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
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\OperatorAgentCommission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6B feature tests — operator-side commission settings endpoints.
 *
 * Routes covered (from routes/api.php):
 *   GET    /api/operator/commission-settings
 *   PATCH  /api/operator/commission-settings
 *   PATCH  /api/operator/commission-settings/agents/{agent}
 *   DELETE /api/operator/commission-settings/agents/{agent}
 *
 * Acceptance criteria for these tests are pulled straight from the
 * controller doc-block + roadmap notes:
 *   - GET returns null default + empty overrides for a fresh operator
 *   - PATCH default upserts the default row (insert + update path)
 *   - PATCH override upserts a per-agent row
 *   - Override against own company is rejected (422)
 *   - DELETE override removes the row
 *   - Unauthenticated callers are rejected (401)
 *   - User without the operator company association sees 404
 */
class OperatorCommissionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_is_rejected(): void
    {
        $this->getJson('/api/operator/commission-settings')->assertStatus(401);
    }

    public function test_user_without_company_membership_gets_404(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/operator/commission-settings')->assertStatus(404);
    }

    public function test_index_returns_empty_state_for_fresh_operator(): void
    {
        $operator = $this->makeCompany('operator');
        $user = $this->makeUserForCompany($operator);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/operator/commission-settings');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.operator_company_id', $operator->id);
        $response->assertJsonPath('data.default', null);
        $response->assertJsonPath('data.overrides', []);
        $response->assertJsonPath('data.available_bases', OperatorAgentCommission::CALCULATION_BASES);
    }

    public function test_upsert_default_creates_then_updates_default_row(): void
    {
        $operator = $this->makeCompany('operator');
        $user = $this->makeUserForCompany($operator);
        Sanctum::actingAs($user);

        // First upsert — create
        $createResponse = $this->patchJson('/api/operator/commission-settings', [
            'calculation_base' => 'gross',
            'default_percentage' => 5,
            'notes' => 'starter rate',
        ]);
        $createResponse->assertOk();
        $createResponse->assertJsonPath('data.default.calculation_base', 'gross');
        // JSON-encoded floats lose trailing .0 when whole, so compare loosely.
        $this->assertEquals(5.0, $createResponse->json('data.default.default_percentage'));

        $this->assertDatabaseCount('operator_agent_commission', 1);

        // Second upsert — update (same operator, agent_company_id still null)
        $updateResponse = $this->patchJson('/api/operator/commission-settings', [
            'calculation_base' => 'post_platform_fee',
            'default_percentage' => 7.5,
            'notes' => null,
        ]);
        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.default.calculation_base', 'post_platform_fee');
        $updateResponse->assertJsonPath('data.default.default_percentage', 7.5);

        // Still exactly one row — confirms updateOrCreate semantics.
        $this->assertDatabaseCount('operator_agent_commission', 1);
    }

    public function test_upsert_default_rejects_invalid_calculation_base(): void
    {
        $operator = $this->makeCompany('operator');
        Sanctum::actingAs($this->makeUserForCompany($operator));

        $this->patchJson('/api/operator/commission-settings', [
            'calculation_base' => 'bogus_mode',
            'default_percentage' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors(['calculation_base']);
    }

    public function test_upsert_override_creates_per_agent_row_and_appears_in_index(): void
    {
        $operator = $this->makeCompany('operator');
        $agent = $this->makeCompany('agent');
        Sanctum::actingAs($this->makeUserForCompany($operator));

        $this->patchJson("/api/operator/commission-settings/agents/{$agent->id}", [
            'calculation_base' => 'gross',
            'default_percentage' => 15,
            'notes' => 'strategic partner',
        ])->assertOk();

        $this->assertDatabaseHas('operator_agent_commission', [
            'operator_company_id' => $operator->id,
            'agent_company_id' => $agent->id,
            'default_percentage' => '15.000',
        ]);

        $index = $this->getJson('/api/operator/commission-settings');
        $index->assertOk();
        $index->assertJsonCount(1, 'data.overrides');
        $index->assertJsonPath('data.overrides.0.agent_company_id', $agent->id);
        $this->assertEquals(15.0, $index->json('data.overrides.0.default_percentage'));
    }

    public function test_upsert_override_against_self_returns_422(): void
    {
        $operator = $this->makeCompany('operator');
        Sanctum::actingAs($this->makeUserForCompany($operator));

        $response = $this->patchJson("/api/operator/commission-settings/agents/{$operator->id}", [
            'calculation_base' => 'gross',
            'default_percentage' => 10,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_delete_override_removes_row(): void
    {
        $operator = $this->makeCompany('operator');
        $agent = $this->makeCompany('agent');
        Sanctum::actingAs($this->makeUserForCompany($operator));

        // Seed an override first.
        OperatorAgentCommission::query()->create([
            'operator_company_id' => $operator->id,
            'agent_company_id' => $agent->id,
            'calculation_base' => 'gross',
            'default_percentage' => 12,
        ]);

        $this->deleteJson("/api/operator/commission-settings/agents/{$agent->id}")
            ->assertOk();

        $this->assertDatabaseMissing('operator_agent_commission', [
            'operator_company_id' => $operator->id,
            'agent_company_id' => $agent->id,
        ]);
    }

    private function makeCompany(string $type): Company
    {
        return Company::query()->create([
            'name' => 'Phase 6B '.$type.' '.str()->uuid(),
            'type' => $type,
            'status' => 'active',
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Phase 6B Test',
            'email' => 'phase6b-'.str()->uuid().'@example.test',
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

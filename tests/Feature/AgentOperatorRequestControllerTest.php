<?php

namespace Tests\Feature;

use App\Models\AgentOperatorRequest;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7.7 feature tests — agent ↔ operator request inbox.
 *
 * Routes:
 *   GET   /api/agent-operator-requests
 *   POST  /api/agent-operator-requests
 *   GET   /api/agent-operator-requests/{id}
 *   PATCH /api/agent-operator-requests/{id}/status
 *
 * Visibility rules:
 *   - Requester sees their own
 *   - Members of requester_company see their company's outbox
 *   - Members of target_company see the request in their inbox
 *   - Box filter (?box=inbox|outbox) narrows further
 */
class AgentOperatorRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_is_rejected(): void
    {
        $this->getJson('/api/agent-operator-requests')->assertStatus(401);
        $this->postJson('/api/agent-operator-requests', [])->assertStatus(401);
    }

    public function test_index_shows_requests_user_is_party_to(): void
    {
        $agent = $this->makeCompany('agent');
        $operator = $this->makeCompany('operator');
        $unrelated = $this->makeCompany('operator');

        $agentUser = $this->makeUserForCompany($agent);

        $own = AgentOperatorRequest::query()->create([
            'requester_user_id' => $agentUser->id,
            'requester_company_id' => $agent->id,
            'target_company_id' => $operator->id,
            'subject' => 'Mine',
            'body' => 'I sent this',
            'status' => 'open',
        ]);
        $other = AgentOperatorRequest::query()->create([
            'requester_user_id' => $this->makeUser()->id,
            'requester_company_id' => $unrelated->id,
            'target_company_id' => $operator->id,
            'subject' => 'Other',
            'body' => 'someone else',
            'status' => 'open',
        ]);

        Sanctum::actingAs($agentUser);

        $response = $this->getJson('/api/agent-operator-requests');
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($own->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_inbox_box_filter_shows_only_received_requests(): void
    {
        $agent = $this->makeCompany('agent');
        $operator = $this->makeCompany('operator');
        $operatorUser = $this->makeUserForCompany($operator);
        $agentUser = $this->makeUserForCompany($agent);

        AgentOperatorRequest::query()->create([
            'requester_user_id' => $agentUser->id,
            'requester_company_id' => $agent->id,
            'target_company_id' => $operator->id,
            'subject' => 'Inbox row',
            'body' => 'sent to operator',
            'status' => 'open',
        ]);
        AgentOperatorRequest::query()->create([
            'requester_user_id' => $operatorUser->id,
            'requester_company_id' => $operator->id,
            'target_company_id' => $agent->id,
            'subject' => 'Outbox row',
            'body' => 'sent to agent',
            'status' => 'open',
        ]);

        Sanctum::actingAs($operatorUser);

        $inbox = $this->getJson('/api/agent-operator-requests?box=inbox');
        $inbox->assertOk();
        $subjects = collect($inbox->json('data'))->pluck('subject')->all();
        $this->assertContains('Inbox row', $subjects);
        $this->assertNotContains('Outbox row', $subjects);

        $outbox = $this->getJson('/api/agent-operator-requests?box=outbox');
        $outbox->assertOk();
        $outboxSubjects = collect($outbox->json('data'))->pluck('subject')->all();
        $this->assertContains('Outbox row', $outboxSubjects);
        $this->assertNotContains('Inbox row', $outboxSubjects);
    }

    public function test_store_creates_request_with_requester_company_attached(): void
    {
        $agent = $this->makeCompany('agent');
        $operator = $this->makeCompany('operator');
        $agentUser = $this->makeUserForCompany($agent);
        Sanctum::actingAs($agentUser);

        $response = $this->postJson('/api/agent-operator-requests', [
            'target_company_id' => $operator->id,
            'subject' => 'Need a flight quote',
            'body' => 'Looking for IST→EVN for a group of 12.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.subject', 'Need a flight quote');
        $response->assertJsonPath('data.status', 'open');
        $response->assertJsonPath('data.target_company.id', $operator->id);
        $this->assertDatabaseHas('agent_operator_requests', [
            'requester_user_id' => $agentUser->id,
            'requester_company_id' => $agent->id,
            'target_company_id' => $operator->id,
        ]);
    }

    public function test_update_status_marks_resolved_with_resolver_metadata(): void
    {
        $agent = $this->makeCompany('agent');
        $operator = $this->makeCompany('operator');
        $agentUser = $this->makeUserForCompany($agent);
        $operatorUser = $this->makeUserForCompany($operator);

        $row = AgentOperatorRequest::query()->create([
            'requester_user_id' => $agentUser->id,
            'requester_company_id' => $agent->id,
            'target_company_id' => $operator->id,
            'subject' => 'For resolution',
            'body' => '...',
            'status' => 'open',
        ]);

        Sanctum::actingAs($operatorUser);

        $response = $this->patchJson("/api/agent-operator-requests/{$row->id}/status", [
            'status' => 'resolved',
            'resolution_notes' => 'Done, see email',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'resolved');
        $response->assertJsonPath('data.resolution_notes', 'Done, see email');
        $response->assertJsonPath('data.resolved_by.id', $operatorUser->id);

        $this->assertNotNull($row->fresh()->resolved_at);
    }

    public function test_show_returns_404_for_non_party_user(): void
    {
        $agent = $this->makeCompany('agent');
        $operator = $this->makeCompany('operator');
        $row = AgentOperatorRequest::query()->create([
            'requester_user_id' => $this->makeUser()->id,
            'requester_company_id' => $agent->id,
            'target_company_id' => $operator->id,
            'subject' => 'Confidential',
            'body' => '...',
            'status' => 'open',
        ]);

        // A user not attached to either company:
        Sanctum::actingAs($this->makeUser());

        $this->getJson("/api/agent-operator-requests/{$row->id}")->assertStatus(404);
    }

    private function makeCompany(string $type): Company
    {
        return Company::query()->create([
            'name' => 'Phase 7.7 '.$type.' '.str()->uuid(),
            'type' => $type,
            'status' => 'active',
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Phase 7.7 Test',
            'email' => 'p77-'.str()->uuid().'@example.test',
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

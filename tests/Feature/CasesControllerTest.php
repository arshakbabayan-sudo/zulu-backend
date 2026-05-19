<?php

namespace Tests\Feature;

use App\Models\AdminCase;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7.10 feature tests — case management endpoints.
 *
 * Routes:
 *   GET   /api/cases
 *   POST  /api/cases
 *   GET   /api/cases/{id}
 *   PATCH /api/cases/{id}
 *
 * Staff visibility: opened_by_user_id, assigned_to_user_id, or
 * company_id ∈ user's companies. Status transitions to closed/resolved
 * stamp closed_at; transitions back to an open status clear it.
 */
class CasesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_is_rejected(): void
    {
        $this->getJson('/api/cases')->assertStatus(401);
        $this->postJson('/api/cases', [])->assertStatus(401);
    }

    public function test_index_filters_by_status_and_priority(): void
    {
        $user = $this->makeUser();

        AdminCase::query()->create([
            'case_number' => 'C-1',
            'title' => 'Urgent thing',
            'description' => '...',
            'status' => 'open',
            'priority' => 'urgent',
            'opened_by_user_id' => $user->id,
            'opened_at' => now(),
        ]);
        AdminCase::query()->create([
            'case_number' => 'C-2',
            'title' => 'Normal thing',
            'description' => '...',
            'status' => 'resolved',
            'priority' => 'normal',
            'opened_by_user_id' => $user->id,
            'opened_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $urgent = $this->getJson('/api/cases?priority=urgent');
        $urgent->assertOk();
        $this->assertCount(1, $urgent->json('data'));
        $this->assertSame('Urgent thing', $urgent->json('data.0.title'));

        $resolved = $this->getJson('/api/cases?status=resolved');
        $resolved->assertOk();
        $this->assertCount(1, $resolved->json('data'));
        $this->assertSame('Normal thing', $resolved->json('data.0.title'));
    }

    public function test_index_scopes_to_user_visibility_when_not_platform_admin(): void
    {
        $user = $this->makeUser();
        $stranger = $this->makeUser();

        // Visible: opened by user
        AdminCase::query()->create([
            'case_number' => 'C-A',
            'title' => 'Mine',
            'description' => '...',
            'status' => 'open',
            'priority' => 'normal',
            'opened_by_user_id' => $user->id,
            'opened_at' => now(),
        ]);
        // Invisible: opened by stranger, not assigned to user, no company link
        AdminCase::query()->create([
            'case_number' => 'C-B',
            'title' => 'Someone else',
            'description' => '...',
            'status' => 'open',
            'priority' => 'normal',
            'opened_by_user_id' => $stranger->id,
            'opened_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/cases');
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Mine', $titles);
        $this->assertNotContains('Someone else', $titles);
    }

    public function test_store_creates_case_with_generated_number(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/cases', [
            'title' => 'Refund disputed',
            'description' => 'Customer claims double charge.',
            'priority' => 'high',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.title', 'Refund disputed');
        $response->assertJsonPath('data.status', 'open');
        $response->assertJsonPath('data.priority', 'high');

        // case_number starts with "C-YYYYMMDD-" then 6 hex chars
        $caseNumber = $response->json('data.case_number');
        $this->assertMatchesRegularExpression('/^C-\d{8}-[A-F0-9]{6}$/', $caseNumber);
    }

    public function test_store_defaults_priority_to_normal(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/cases', [
            'title' => 'No priority given',
            'description' => '...',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.priority', 'normal');
    }

    public function test_update_to_resolved_stamps_closed_at(): void
    {
        $user = $this->makeUser();
        $row = AdminCase::query()->create([
            'case_number' => 'C-RESOLVE',
            'title' => 'Pending resolution',
            'description' => '...',
            'status' => 'open',
            'priority' => 'normal',
            'opened_by_user_id' => $user->id,
            'opened_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/cases/{$row->id}", [
            'status' => 'resolved',
            'closing_notes' => 'Refund issued',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'resolved');
        $response->assertJsonPath('data.closing_notes', 'Refund issued');
        $this->assertNotNull($response->json('data.closed_at'));
    }

    public function test_update_reopen_clears_closed_at(): void
    {
        $user = $this->makeUser();
        $row = AdminCase::query()->create([
            'case_number' => 'C-REOPEN',
            'title' => 'Already closed',
            'description' => '...',
            'status' => 'closed',
            'priority' => 'normal',
            'opened_by_user_id' => $user->id,
            'opened_at' => now()->subDay(),
            'closed_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/cases/{$row->id}", [
            'status' => 'open',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'open');
        $this->assertNull($response->json('data.closed_at'));
    }

    public function test_show_returns_case_by_id(): void
    {
        $user = $this->makeUser();
        $row = AdminCase::query()->create([
            'case_number' => 'C-SHOW',
            'title' => 'For show',
            'description' => '...',
            'status' => 'open',
            'priority' => 'normal',
            'opened_by_user_id' => $user->id,
            'opened_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/cases/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.case_number', 'C-SHOW');

        $this->getJson('/api/cases/99999')->assertStatus(404);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Phase 7.10 Test',
            'email' => 'p710-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    // -----------------------------------------------------------------
    // Reply threading (Path D A1 follow-up)
    // -----------------------------------------------------------------

    public function test_reply_thread_lists_in_chronological_order(): void
    {
        $opener = $this->makeUser();
        $case = AdminCase::query()->create([
            'case_number' => 'C-R-1',
            'title' => 'Need help',
            'description' => 'First contact',
            'status' => 'open',
            'priority' => 'normal',
            'opened_by_user_id' => $opener->id,
            'opened_at' => now(),
        ]);

        \App\Models\CaseReply::query()->create([
            'case_id' => $case->id,
            'user_id' => $opener->id,
            'body' => 'First reply',
            'visibility' => 'public',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);
        \App\Models\CaseReply::query()->create([
            'case_id' => $case->id,
            'user_id' => $opener->id,
            'body' => 'Second reply',
            'visibility' => 'public',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        Sanctum::actingAs($opener);

        $response = $this->getJson("/api/cases/{$case->id}/replies");
        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals('First reply', $response->json('data.0.body'));
        $this->assertEquals('Second reply', $response->json('data.1.body'));
    }

    public function test_post_reply_is_visible_in_subsequent_list(): void
    {
        $opener = $this->makeUser();
        $case = AdminCase::query()->create([
            'case_number' => 'C-R-2',
            'title' => 'Need help',
            'description' => '',
            'status' => 'open',
            'priority' => 'normal',
            'opened_by_user_id' => $opener->id,
            'opened_at' => now(),
        ]);

        Sanctum::actingAs($opener);

        $response = $this->postJson("/api/cases/{$case->id}/replies", [
            'body' => 'Hello there',
        ]);
        $response->assertStatus(201);
        $this->assertSame('public', $response->json('data.visibility'));

        $list = $this->getJson("/api/cases/{$case->id}/replies");
        $this->assertCount(1, $list->json('data'));
    }

    public function test_internal_replies_are_hidden_from_opener(): void
    {
        $opener = $this->makeUser();
        $assignee = $this->makeUser();
        $case = AdminCase::query()->create([
            'case_number' => 'C-R-3',
            'title' => 'Internal',
            'description' => '',
            'status' => 'open',
            'priority' => 'normal',
            'opened_by_user_id' => $opener->id,
            'assigned_to_user_id' => $assignee->id,
            'opened_at' => now(),
        ]);

        \App\Models\CaseReply::query()->create([
            'case_id' => $case->id,
            'user_id' => $assignee->id,
            'body' => 'Public reply',
            'visibility' => 'public',
        ]);
        \App\Models\CaseReply::query()->create([
            'case_id' => $case->id,
            'user_id' => $assignee->id,
            'body' => 'Internal note — needs escalation',
            'visibility' => 'internal',
        ]);

        // Opener: only sees public.
        Sanctum::actingAs($opener);
        $openerResp = $this->getJson("/api/cases/{$case->id}/replies");
        $this->assertCount(1, $openerResp->json('data'));
        $this->assertSame('Public reply', $openerResp->json('data.0.body'));

        // Assignee: sees both.
        Sanctum::actingAs($assignee);
        $assigneeResp = $this->getJson("/api/cases/{$case->id}/replies");
        $this->assertCount(2, $assigneeResp->json('data'));
    }

    public function test_opener_posting_internal_is_silently_downgraded_to_public(): void
    {
        $opener = $this->makeUser();
        $case = AdminCase::query()->create([
            'case_number' => 'C-R-4',
            'title' => 'Test',
            'description' => '',
            'status' => 'open',
            'priority' => 'normal',
            'opened_by_user_id' => $opener->id,
            'opened_at' => now(),
        ]);

        Sanctum::actingAs($opener);

        $response = $this->postJson("/api/cases/{$case->id}/replies", [
            'body' => 'Pretending to be internal',
            'visibility' => 'internal',
        ]);
        $response->assertStatus(201);
        $this->assertSame('public', $response->json('data.visibility'));
    }

    public function test_reply_on_closed_case_reopens_it(): void
    {
        $opener = $this->makeUser();
        $case = AdminCase::query()->create([
            'case_number' => 'C-R-5',
            'title' => 'Test',
            'description' => '',
            'status' => 'closed',
            'priority' => 'normal',
            'opened_by_user_id' => $opener->id,
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($opener);
        $this->postJson("/api/cases/{$case->id}/replies", ['body' => 'I have more info'])
            ->assertStatus(201);

        $case->refresh();
        $this->assertSame('open', $case->status);
        $this->assertNull($case->closed_at);
    }

    // -----------------------------------------------------------------
    // SLA timers + escalation (Path D A2 follow-up)
    // -----------------------------------------------------------------

    public function test_store_computes_sla_due_at_based_on_priority(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/cases', [
            'title' => 'Urgent thing',
            'description' => 'Broken',
            'priority' => 'urgent',
        ]);
        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.sla_due_at'));
        // urgent = 2h from now; sla_remaining_minutes should be around 120.
        $remaining = (int) $response->json('data.sla_remaining_minutes');
        $this->assertGreaterThan(115, $remaining);
        $this->assertLessThan(125, $remaining);
    }

    public function test_priority_change_reanchors_sla_deadline(): void
    {
        $opener = $this->makeUser();
        $case = AdminCase::query()->create([
            'case_number' => 'C-SLA-1',
            'title' => 'Slow priority',
            'description' => 'x',
            'status' => 'open',
            'priority' => 'low',
            'sla_due_at' => now()->addHours(72),
            'opened_by_user_id' => $opener->id,
            'opened_at' => now(),
        ]);

        Sanctum::actingAs($opener);
        $response = $this->patchJson("/api/cases/{$case->id}", ['priority' => 'urgent']);
        $response->assertOk();

        // urgent = 2h from now (re-anchored on patch time)
        $remaining = (int) $response->json('data.sla_remaining_minutes');
        $this->assertGreaterThan(115, $remaining);
        $this->assertLessThan(125, $remaining);
    }

    public function test_escalate_overdue_command_marks_due_cases(): void
    {
        $opener = $this->makeUser();
        $overdue = AdminCase::query()->create([
            'case_number' => 'C-SLA-OD',
            'title' => 'Overdue',
            'description' => 'x',
            'status' => 'open',
            'priority' => 'urgent',
            'sla_due_at' => now()->subHours(1),
            'opened_by_user_id' => $opener->id,
            'opened_at' => now()->subHours(3),
        ]);
        $fresh = AdminCase::query()->create([
            'case_number' => 'C-SLA-FR',
            'title' => 'Fresh',
            'description' => 'x',
            'status' => 'open',
            'priority' => 'high',
            'sla_due_at' => now()->addHours(3),
            'opened_by_user_id' => $opener->id,
            'opened_at' => now(),
        ]);

        $this->artisan('cases:escalate-overdue')->assertExitCode(0);

        $overdue->refresh();
        $fresh->refresh();
        $this->assertSame('escalated', $overdue->status);
        $this->assertNotNull($overdue->escalated_at);
        $this->assertSame('open', $fresh->status);
        $this->assertNull($fresh->escalated_at);
    }

    public function test_escalate_command_skips_resolved_and_closed_cases(): void
    {
        $opener = $this->makeUser();
        $resolved = AdminCase::query()->create([
            'case_number' => 'C-SLA-RES',
            'title' => 'Already resolved',
            'description' => 'x',
            'status' => 'resolved',
            'priority' => 'urgent',
            'sla_due_at' => now()->subHours(5),
            'opened_by_user_id' => $opener->id,
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subHour(),
        ]);

        $this->artisan('cases:escalate-overdue')->assertExitCode(0);

        $resolved->refresh();
        $this->assertSame('resolved', $resolved->status);
        $this->assertNull($resolved->escalated_at);
    }

    public function test_user_outside_case_visibility_cannot_view_or_post(): void
    {
        $opener = $this->makeUser();
        $stranger = $this->makeUser();
        $case = AdminCase::query()->create([
            'case_number' => 'C-R-6',
            'title' => 'Private',
            'description' => '',
            'status' => 'open',
            'priority' => 'normal',
            'opened_by_user_id' => $opener->id,
            'opened_at' => now(),
        ]);

        Sanctum::actingAs($stranger);
        $this->getJson("/api/cases/{$case->id}/replies")->assertStatus(403);
        $this->postJson("/api/cases/{$case->id}/replies", ['body' => 'Hi'])->assertStatus(403);
    }
}

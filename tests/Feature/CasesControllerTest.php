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
}

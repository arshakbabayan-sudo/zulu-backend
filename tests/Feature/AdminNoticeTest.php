<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendBulkNotificationJob;
use App\Models\AdminNotice;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap §4 (2026-06-12) — Settings → System notifications CUD.
 *
 * Admin notices: authored announcements with audience + optional scheduling,
 * fanned out through the SAME SendBulkNotificationJob bulk-send uses.
 * Super-admin only.
 */
class AdminNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::query()->firstOrCreate(['name' => 'company_admin']);
    }

    private function superAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }

    private function operator(): User
    {
        $company = Company::query()->create(['name' => 'Notice Op Co', 'type' => 'operator']);
        $u = User::factory()->create();
        $u->companies()->attach($company->id, ['role_id' => (int) Role::query()->where('name', 'company_admin')->value('id')]);

        return $u->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Scheduled maintenance 02:00-03:00',
            'message' => 'The platform will be briefly unavailable.',
            'type' => 'maintenance',
            'audience' => 'all_staff',
            'channels' => ['in_app'],
            'priority' => 'high',
        ], $overrides);
    }

    public function test_super_creates_draft_then_sends_now(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->superAdmin());

        $id = $this->postJson('/api/platform-admin/notices', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        Queue::assertNothingPushed();

        $this->postJson("/api/platform-admin/notices/{$id}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        Queue::assertPushed(SendBulkNotificationJob::class, 1);
        $this->assertNotNull(AdminNotice::query()->find($id)->sent_at);
        $this->assertGreaterThan(0, AdminNotice::query()->find($id)->sent_count);

        // A sent notice is frozen.
        $this->patchJson("/api/platform-admin/notices/{$id}", ['title' => 'edited'])
            ->assertStatus(422);
        $this->postJson("/api/platform-admin/notices/{$id}/send")
            ->assertStatus(422);
    }

    public function test_send_now_flag_on_create_dispatches_immediately(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/platform-admin/notices', $this->payload(['send_now' => true]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'sent');

        Queue::assertPushed(SendBulkNotificationJob::class, 1);
    }

    public function test_empty_audience_returns_422_and_keeps_draft(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->superAdmin());

        $emptyCompany = Company::query()->create(['name' => 'Empty Co', 'type' => 'operator']);

        $this->postJson('/api/platform-admin/notices', $this->payload([
            'audience' => 'by_company',
            'company_id' => $emptyCompany->id,
            'send_now' => true,
        ]))->assertStatus(422);

        Queue::assertNothingPushed();
        // The row survives as an editable draft.
        $this->assertSame(1, AdminNotice::query()->whereNull('sent_at')->count());
    }

    public function test_update_and_delete_before_send(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $id = $this->postJson('/api/platform-admin/notices', $this->payload([
            'scheduled_for' => now()->addDay()->toIso8601String(),
        ]))->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data.id');

        $this->patchJson("/api/platform-admin/notices/{$id}", ['is_active' => false, 'title' => 'Paused notice'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.title', 'Paused notice');

        $this->deleteJson("/api/platform-admin/notices/{$id}")->assertOk();
        $this->assertSame(0, AdminNotice::query()->count());
    }

    public function test_send_due_command_sends_only_due_active_notices(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->superAdmin());

        $mk = fn (array $o) => $this->postJson('/api/platform-admin/notices', $this->payload($o))->json('data.id');
        $due = $mk(['title' => 'due', 'scheduled_for' => now()->subMinute()->toIso8601String()]);
        $future = $mk(['title' => 'future', 'scheduled_for' => now()->addDay()->toIso8601String()]);
        $paused = $mk(['title' => 'paused', 'scheduled_for' => now()->subMinute()->toIso8601String(), 'is_active' => false]);

        $this->artisan('notices:send-due')->assertSuccessful();

        Queue::assertPushed(SendBulkNotificationJob::class, 1);
        $this->assertNotNull(AdminNotice::query()->find($due)->sent_at);
        $this->assertNull(AdminNotice::query()->find($future)->sent_at);
        $this->assertNull(AdminNotice::query()->find($paused)->sent_at);
    }

    public function test_non_super_admin_gets_403(): void
    {
        Sanctum::actingAs($this->operator());

        $this->getJson('/api/platform-admin/notices')->assertForbidden();
        $this->postJson('/api/platform-admin/notices', $this->payload())->assertForbidden();
    }
}

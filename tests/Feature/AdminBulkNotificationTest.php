<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7.6 feature tests — bulk-send notification endpoint.
 *
 * Route: POST /api/platform-admin/notifications/bulk-send (super-admin gated)
 *
 * Target selectors:
 *   - all_b2c       → users without memberships
 *   - all_staff     → users with memberships
 *   - by_company    → users attached to a given company_id
 *   - specific_users → explicit user_ids list
 */
class AdminBulkNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_caller_is_rejected(): void
    {
        $this->postJson('/api/platform-admin/notifications/bulk-send', [])->assertStatus(401);
    }

    public function test_non_super_admin_is_forbidden(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/platform-admin/notifications/bulk-send', [
            'target' => 'all_b2c',
            'title' => 'Hi',
            'message' => 'hello',
        ])->assertStatus(403);
    }

    public function test_bulk_send_to_specific_users_inserts_one_notification_per_user(): void
    {
        $admin = $this->makeSuperAdmin();
        $a = $this->makeUser();
        $b = $this->makeUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/platform-admin/notifications/bulk-send', [
            'target' => 'specific_users',
            'user_ids' => [$a->id, $b->id],
            'title' => 'Maintenance window',
            'message' => 'API down at 03:00 UTC.',
            'priority' => 'high',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.sent_count', 2);

        $rows = DB::table('notifications')->where('type', 'admin_broadcast')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('Maintenance window', $rows->first()->title);
        $this->assertSame('high', $rows->first()->priority);
    }

    public function test_bulk_send_to_all_b2c_includes_users_without_memberships_only(): void
    {
        $admin = $this->makeSuperAdmin();
        $company = $this->makeCompany();
        $role = Role::query()->firstOrCreate(['name' => 'company_admin']);

        $b2c = $this->makeUser();
        $staff = $this->makeUser();
        $staff->companies()->attach($company->id, ['role_id' => $role->id]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/platform-admin/notifications/bulk-send', [
            'target' => 'all_b2c',
            'title' => 'B2C only',
            'message' => 'just B2C',
        ]);

        $response->assertOk();

        $recipientIds = DB::table('notifications')
            ->where('type', 'admin_broadcast')
            ->pluck('user_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $this->assertContains($b2c->id, $recipientIds);
        $this->assertNotContains($staff->id, $recipientIds);
    }

    public function test_bulk_send_to_by_company_targets_company_members_only(): void
    {
        $admin = $this->makeSuperAdmin();
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $role = Role::query()->firstOrCreate(['name' => 'company_admin']);

        $userA = $this->makeUser();
        $userA->companies()->attach($companyA->id, ['role_id' => $role->id]);

        $userB = $this->makeUser();
        $userB->companies()->attach($companyB->id, ['role_id' => $role->id]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/platform-admin/notifications/bulk-send', [
            'target' => 'by_company',
            'company_id' => $companyA->id,
            'title' => 'Company A only',
            'message' => 'team meeting',
        ]);

        $response->assertOk();

        $recipientIds = DB::table('notifications')
            ->where('type', 'admin_broadcast')
            ->pluck('user_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $this->assertContains($userA->id, $recipientIds);
        $this->assertNotContains($userB->id, $recipientIds);
    }

    public function test_bulk_send_with_no_matching_recipients_returns_422(): void
    {
        $admin = $this->makeSuperAdmin();
        Sanctum::actingAs($admin);

        // The DB has no other users besides the admin (who has memberships),
        // so all_b2c yields 0 recipients.
        $response = $this->postJson('/api/platform-admin/notifications/bulk-send', [
            'target' => 'all_b2c',
            'title' => 'Will go nowhere',
            'message' => '...',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'No recipients matched the selector.');
    }

    public function test_bulk_send_validates_required_fields(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $this->postJson('/api/platform-admin/notifications/bulk-send', [
            'target' => 'invalid_selector',
            'title' => 'X',
            'message' => 'Y',
        ])->assertStatus(422)->assertJsonValidationErrors(['target']);

        $this->postJson('/api/platform-admin/notifications/bulk-send', [
            'target' => 'all_b2c',
        ])->assertStatus(422)->assertJsonValidationErrors(['title', 'message']);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Phase 7.6 Test',
            'email' => 'p76-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Phase 7.6 '.str()->uuid(),
            'type' => 'operator',
        ]);
    }

    private function makeSuperAdmin(): User
    {
        $user = $this->makeUser();
        $role = Role::query()->firstOrCreate(['name' => 'super_admin']);
        $platform = $this->makeCompany();
        $user->companies()->attach($platform->id, ['role_id' => $role->id]);
        // Controller checks $user->is_super_admin directly (dynamic attr).
        $user->is_super_admin = true;

        return $user;
    }
}

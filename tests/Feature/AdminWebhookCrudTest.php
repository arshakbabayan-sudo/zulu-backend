<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookSubscription;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap §4 (2026-06-12) — Settings → Webhooks CUD.
 *
 * Admin-side subscription management: create (secret shown once),
 * update/pause, delete; writes go through WebhookService so validation
 * matches the seller self-service path.
 */
class AdminWebhookCrudTest extends TestCase
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

    private function company(string $name = 'Hook Co'): Company
    {
        return Company::query()->create(['name' => $name, 'type' => 'operator']);
    }

    public function test_super_creates_subscription_secret_shown_once(): void
    {
        $company = $this->company();
        Sanctum::actingAs($this->superAdmin());

        $res = $this->postJson('/api/platform-admin/webhooks/subscriptions', [
            'company_id' => $company->id,
            'target_url' => 'https://example.com/hook',
            'events' => ['order.paid', 'voucher.issued'],
            'description' => 'CRM sync',
        ])->assertCreated();

        $secret = $res->json('data.secret');
        $this->assertIsString($secret);
        $this->assertSame(64, strlen($secret));
        $res->assertJsonPath('data.target_url', 'https://example.com/hook')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.company.name', $company->name);

        // The list never exposes the secret.
        $list = $this->getJson('/api/platform-admin/webhooks/subscriptions')->assertOk()->json('data');
        $this->assertArrayNotHasKey('secret', $list[0]);
        $this->assertSame('https://example.com/hook', $list[0]['target_url']);
    }

    public function test_unsupported_event_is_422(): void
    {
        $company = $this->company();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/platform-admin/webhooks/subscriptions', [
            'company_id' => $company->id,
            'target_url' => 'https://example.com/hook',
            'events' => ['nonsense.event'],
        ])->assertStatus(422);
    }

    public function test_update_edits_fields_and_pauses(): void
    {
        $company = $this->company();
        Sanctum::actingAs($this->superAdmin());
        $id = $this->postJson('/api/platform-admin/webhooks/subscriptions', [
            'company_id' => $company->id,
            'target_url' => 'https://example.com/hook',
            'events' => ['order.paid'],
        ])->json('data.id');

        $this->patchJson("/api/platform-admin/webhooks/subscriptions/{$id}", [
            'target_url' => 'https://example.com/hook-v2',
            'events' => ['order.paid', 'order.confirmed'],
            'active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.target_url', 'https://example.com/hook-v2')
            ->assertJsonPath('data.active', false);

        $this->assertSame(
            ['order.paid', 'order.confirmed'],
            WebhookSubscription::query()->find($id)->events,
        );

        // Secret survives the edit untouched.
        $this->assertSame(64, strlen((string) WebhookSubscription::query()->find($id)->secret));
    }

    public function test_delete_soft_deletes(): void
    {
        $company = $this->company();
        Sanctum::actingAs($this->superAdmin());
        $id = $this->postJson('/api/platform-admin/webhooks/subscriptions', [
            'company_id' => $company->id,
            'target_url' => 'https://example.com/hook',
            'events' => ['order.paid'],
        ])->json('data.id');

        $this->deleteJson("/api/platform-admin/webhooks/subscriptions/{$id}")->assertOk();
        $this->assertSoftDeleted('webhook_subscriptions', ['id' => $id]);
        $this->deleteJson("/api/platform-admin/webhooks/subscriptions/{$id}")->assertNotFound();
    }

    public function test_operator_writes_are_blocked_by_middleware(): void
    {
        $company = $this->company();
        $operator = User::factory()->create();
        $operator->companies()->attach($company->id, ['role_id' => (int) Role::query()->where('name', 'company_admin')->value('id')]);

        Sanctum::actingAs($operator->fresh());

        $this->postJson('/api/platform-admin/webhooks/subscriptions', [
            'company_id' => $company->id,
            'target_url' => 'https://example.com/hook',
            'events' => ['order.paid'],
        ])->assertForbidden();
    }
}

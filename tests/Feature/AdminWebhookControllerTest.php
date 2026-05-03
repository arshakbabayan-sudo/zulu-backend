<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the Sprint 52 + 75 admin webhook oversight endpoints.
 */
class AdminWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscriptions_index_requires_platform_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/platform-admin/webhooks/subscriptions')->assertStatus(403);
    }

    public function test_stats_returns_counters(): void
    {
        $admin = $this->createPlatformAdmin();
        $company = Company::query()->create([
            'name' => 'Test Co '.uniqid(),
            'type' => 'tour_operator',
            'status' => 'active',
        ]);

        $sub = WebhookSubscription::query()->create([
            'company_id' => $company->id,
            'target_url' => 'https://example.com/hook',
            'events' => ['order.paid'],
            'secret' => bin2hex(random_bytes(16)),
            'active' => true,
        ]);

        WebhookDelivery::query()->create([
            'subscription_id' => $sub->id,
            'event' => 'order.paid',
            'idempotency_key' => 'k1',
            'payload' => ['x' => 1],
            'status' => 'success',
            'attempt_count' => 1,
        ]);
        WebhookDelivery::query()->create([
            'subscription_id' => $sub->id,
            'event' => 'order.paid',
            'idempotency_key' => 'k2',
            'payload' => ['x' => 2],
            'status' => 'failed',
            'attempt_count' => 5,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/webhooks/stats');
        $response->assertOk();

        $this->assertSame(1, $response->json('data.total_subscriptions'));
        $this->assertSame(1, $response->json('data.active_subscriptions'));
        $this->assertSame(2, $response->json('data.deliveries_total'));
        $this->assertSame(1, $response->json('data.deliveries_success'));
        $this->assertSame(1, $response->json('data.deliveries_failed'));
        $this->assertEquals(50.0, $response->json('data.success_rate'));
    }

    public function test_replay_failed_delivery_resets_to_pending(): void
    {
        $admin = $this->createPlatformAdmin();
        $company = Company::query()->create([
            'name' => 'Test Co '.uniqid(),
            'type' => 'tour_operator',
            'status' => 'active',
        ]);

        $sub = WebhookSubscription::query()->create([
            'company_id' => $company->id,
            'target_url' => 'https://example.com/hook',
            'events' => ['order.paid'],
            'secret' => bin2hex(random_bytes(16)),
            'active' => true,
        ]);
        $delivery = WebhookDelivery::query()->create([
            'subscription_id' => $sub->id,
            'event' => 'order.paid',
            'idempotency_key' => 'k-replay',
            'payload' => ['x' => 1],
            'status' => 'failed',
            'attempt_count' => 5,
            'http_status' => 500,
            'error_message' => 'connection refused',
        ]);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/webhooks/deliveries/'.$delivery->id.'/replay');
        $response->assertOk();

        $delivery->refresh();
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(0, $delivery->attempt_count);
        $this->assertNull($delivery->http_status);
        $this->assertNull($delivery->error_message);
    }

    public function test_replay_rejects_non_failed_delivery(): void
    {
        $admin = $this->createPlatformAdmin();
        $company = Company::query()->create([
            'name' => 'Test Co '.uniqid(),
            'type' => 'tour_operator',
            'status' => 'active',
        ]);

        $sub = WebhookSubscription::query()->create([
            'company_id' => $company->id,
            'target_url' => 'https://example.com/hook',
            'events' => ['order.paid'],
            'secret' => bin2hex(random_bytes(16)),
            'active' => true,
        ]);
        $delivery = WebhookDelivery::query()->create([
            'subscription_id' => $sub->id,
            'event' => 'order.paid',
            'idempotency_key' => 'k-success',
            'payload' => ['x' => 1],
            'status' => 'success',
            'attempt_count' => 1,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/platform-admin/webhooks/deliveries/'.$delivery->id.'/replay')
            ->assertStatus(422);
    }

    public function test_dead_letter_lists_only_failed(): void
    {
        $admin = $this->createPlatformAdmin();
        $company = Company::query()->create([
            'name' => 'Test Co '.uniqid(),
            'type' => 'tour_operator',
            'status' => 'active',
        ]);
        $sub = WebhookSubscription::query()->create([
            'company_id' => $company->id,
            'target_url' => 'https://example.com/hook',
            'events' => ['order.paid'],
            'secret' => bin2hex(random_bytes(16)),
            'active' => true,
        ]);

        WebhookDelivery::query()->create([
            'subscription_id' => $sub->id, 'event' => 'order.paid',
            'idempotency_key' => 'a', 'payload' => [], 'status' => 'success', 'attempt_count' => 1,
        ]);
        WebhookDelivery::query()->create([
            'subscription_id' => $sub->id, 'event' => 'order.paid',
            'idempotency_key' => 'b', 'payload' => [], 'status' => 'pending', 'attempt_count' => 2,
        ]);
        WebhookDelivery::query()->create([
            'subscription_id' => $sub->id, 'event' => 'order.paid',
            'idempotency_key' => 'c', 'payload' => [], 'status' => 'failed', 'attempt_count' => 5,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/webhooks/dead-letter');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('failed', $response->json('data.0.status'));
    }

    private function createPlatformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}

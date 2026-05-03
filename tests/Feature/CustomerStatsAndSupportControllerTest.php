<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for Sprint 65 (customer support) + Sprint 71 (customer stats).
 */
class CustomerStatsAndSupportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_stats_endpoint_returns_user_data(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/customer/stats');
        $response->assertOk();
        $this->assertSame($user->id, $response->json('data.user_id'));
        $this->assertSame(0, $response->json('data.orders.total'));
        $this->assertSame(0.0, (float) $response->json('data.spend.total'));
    }

    public function test_customer_can_open_a_support_ticket(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/customer/support/tickets', [
            'subject' => 'Refund not received',
            'priority' => 'high',
            'initial_message' => 'I have not received my refund yet.',
        ]);
        $response->assertCreated();

        $ticketId = $response->json('data.id');
        $this->assertNotNull($ticketId);
        $ticket = SupportTicket::query()->find($ticketId);
        $this->assertSame($user->id, $ticket->user_id);
        $this->assertSame('open', $ticket->status);
        $this->assertSame('high', $ticket->priority);
        $this->assertCount(1, $ticket->messages);
    }

    public function test_customer_can_only_see_own_tickets(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $a = User::factory()->create();
        $b = User::factory()->create();

        SupportTicket::query()->create([
            'user_id' => $a->id,
            'subject' => 'A subject',
            'priority' => 'low',
            'status' => 'open',
        ]);
        SupportTicket::query()->create([
            'user_id' => $b->id,
            'subject' => 'B subject',
            'priority' => 'low',
            'status' => 'open',
        ]);

        Sanctum::actingAs($a);
        $response = $this->getJson('/api/customer/support/tickets');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('A subject', $response->json('data.0.subject'));
    }

    public function test_customer_cannot_reply_to_resolved_ticket(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::factory()->create();

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'subject' => 'Already resolved',
            'priority' => 'low',
            'status' => SupportTicket::STATUS_RESOLVED,
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/customer/support/tickets/{$ticket->id}/messages", [
            'message' => 'still broken',
        ]);
        $response->assertStatus(422);
    }

    public function test_customer_reply_reopens_pending_ticket(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::factory()->create();

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'subject' => 'Pending reply',
            'priority' => 'medium',
            'status' => SupportTicket::STATUS_PENDING,
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/customer/support/tickets/{$ticket->id}/messages", [
            'message' => 'any update?',
        ]);
        $response->assertOk();

        $ticket->refresh();
        $this->assertSame(SupportTicket::STATUS_OPEN, $ticket->status);
    }
}

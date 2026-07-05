<?php

namespace Tests\Feature\Meta;

use App\Models\Company;
use App\Models\Role;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Social inbox read side (CRM): a super-admin lists conversations and opens a
 * thread (which clears the unread badge); a plain company user without the page
 * sees nothing.
 */
class SocialInboxReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'company_admin', 'company_viewer'] as $r) {
            Role::query()->firstOrCreate(['name' => $r]);
        }
    }

    private function superAdmin(): User
    {
        $company = Company::query()->create(['name' => 'HQ', 'type' => 'operator']);
        $user = User::factory()->create();
        $user->companies()->attach($company->id, [
            'role_id' => (int) Role::query()->where('name', 'super_admin')->value('id'),
        ]);

        return $user->fresh();
    }

    private function seedConversation(string $text = 'Բարև'): SocialConversation
    {
        $conv = SocialConversation::query()->create([
            'channel' => 'facebook',
            'page_id' => '112996334711949',
            'psid' => 'PSID_X',
            'unread_count' => 2,
            'last_message_at' => now(),
        ]);
        SocialMessage::query()->create([
            'conversation_id' => $conv->id,
            'direction' => 'in',
            'external_message_id' => 'mid_read_1',
            'sender_psid' => 'PSID_X',
            'text' => $text,
        ]);

        return $conv;
    }

    public function test_super_admin_lists_conversations(): void
    {
        $this->seedConversation('ազատ սենյակ ունե՞ք');
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/platform-admin/crm/social/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.channel', 'facebook')
            ->assertJsonPath('data.0.unread_count', 2)
            ->assertJsonPath('data.0.last_preview', 'ազատ սենյակ ունե՞ք');
    }

    public function test_opening_thread_returns_messages_and_clears_unread(): void
    {
        $conv = $this->seedConversation('hello');
        Sanctum::actingAs($this->superAdmin());

        $this->getJson("/api/platform-admin/crm/social/conversations/{$conv->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.messages.0.text', 'hello')
            ->assertJsonPath('data.messages.0.direction', 'in');

        $this->assertSame(0, $conv->fresh()->unread_count);
    }

    public function test_super_admin_reply_sends_and_records_outbound(): void
    {
        config(['services.meta.page_access_token' => 'PAGETOKEN']);
        Http::fake(['*/me/messages' => Http::response(['message_id' => 'mid_out_1'], 200)]);

        $conv = $this->seedConversation();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/platform-admin/crm/social/conversations/{$conv->id}/reply", [
            'text' => 'Բարև, ազատ ենք',
        ])
            ->assertCreated()
            ->assertJsonPath('data.direction', 'out')
            ->assertJsonPath('data.text', 'Բարև, ազատ ենք');

        $this->assertDatabaseHas('social_messages', [
            'conversation_id' => $conv->id,
            'direction' => 'out',
            'external_message_id' => 'mid_out_1',
        ]);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/me/messages'));
    }

    public function test_reply_returns_422_when_send_fails(): void
    {
        config(['services.meta.page_access_token' => 'PAGETOKEN']);
        Http::fake(['*/me/messages' => Http::response(['error' => ['message' => 'nope']], 400)]);

        $conv = $this->seedConversation();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/platform-admin/crm/social/conversations/{$conv->id}/reply", [
            'text' => 'hi',
        ])->assertStatus(422);

        $this->assertSame(0, SocialMessage::query()->where('direction', 'out')->count());
    }

    public function test_non_super_operator_is_forbidden(): void
    {
        // The social inbox lives under the platform-admin gate and is NOT on the
        // operator read-allowlist, so a plain operator/agent is 403 (super-only
        // for now; per-operator access can be added to EnsurePlatformAdmin later
        // once page→company mapping is in place).
        $this->seedConversation();
        $company = Company::query()->create(['name' => 'Other Co', 'type' => 'operator']);
        $user = User::factory()->create();
        $user->companies()->attach($company->id, [
            'role_id' => (int) Role::query()->where('name', 'company_admin')->value('id'),
        ]);
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/platform-admin/crm/social/conversations')
            ->assertForbidden();
    }
}

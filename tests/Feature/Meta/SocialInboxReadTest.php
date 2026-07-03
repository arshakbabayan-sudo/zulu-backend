<?php

namespace Tests\Feature\Meta;

use App\Models\Company;
use App\Models\Role;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->getJson('/api/crm/social/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.channel', 'facebook')
            ->assertJsonPath('data.0.unread_count', 2)
            ->assertJsonPath('data.0.last_preview', 'ազատ սենյակ ունե՞ք');
    }

    public function test_opening_thread_returns_messages_and_clears_unread(): void
    {
        $conv = $this->seedConversation('hello');
        Sanctum::actingAs($this->superAdmin());

        $this->getJson("/api/crm/social/conversations/{$conv->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.messages.0.text', 'hello')
            ->assertJsonPath('data.messages.0.direction', 'in');

        $this->assertSame(0, $conv->fresh()->unread_count);
    }

    public function test_company_user_without_page_sees_nothing(): void
    {
        $this->seedConversation(); // null company_id
        $company = Company::query()->create(['name' => 'Other Co', 'type' => 'operator']);
        $user = User::factory()->create();
        $user->companies()->attach($company->id, [
            'role_id' => (int) Role::query()->where('name', 'company_viewer')->value('id'),
        ]);
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/crm/social/conversations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}

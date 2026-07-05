<?php

namespace Tests\Feature\Meta;

use App\Models\SocialConversation;
use App\Models\SocialMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Meta (Facebook Messenger) webhook — the social-inbox entrypoint.
 *
 * Covers the two verbs on POST/GET /api/webhooks/meta:
 *   - GET  handshake echoes the challenge iff the verify token matches
 *   - POST stores inbound customer messages (deduped by Meta mid), ignores
 *          echoes/non-message events, and enforces the HMAC signature once the
 *          app secret is configured.
 */
class MetaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function messagePayload(string $psid, string $mid, string $text): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => '112996334711949',
                'time' => 1751000000000,
                'messaging' => [[
                    'sender' => ['id' => $psid],
                    'recipient' => ['id' => '112996334711949'],
                    'timestamp' => 1751000000000,
                    'message' => ['mid' => $mid, 'text' => $text],
                ]],
            ]],
        ];
    }

    /** Post a raw JSON body with an optional signature header. */
    private function postRaw(array $payload, ?string $secret = null)
    {
        $raw = json_encode($payload);
        $headers = ['Content-Type' => 'application/json'];
        if ($secret !== null) {
            $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $raw, $secret);
        }

        return $this->call('POST', '/api/webhooks/meta', [], [], [], $this->transformHeadersToServerVars($headers), $raw);
    }

    public function test_get_handshake_echoes_challenge_when_token_matches(): void
    {
        config(['services.meta.verify_token' => 'verify-abc']);

        $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=verify-abc&hub_challenge=12345')
            ->assertOk()
            ->assertSee('12345');
    }

    public function test_get_handshake_rejected_when_token_wrong(): void
    {
        config(['services.meta.verify_token' => 'verify-abc']);

        $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=WRONG&hub_challenge=12345')
            ->assertForbidden();
    }

    public function test_inbound_message_creates_conversation_and_message(): void
    {
        // No app secret configured → signature check skipped (initial wiring).
        config(['services.meta.app_secret' => '']);

        $this->postRaw($this->messagePayload('PSID_1', 'mid_1', 'Բարև, ազատ սենյակ ունե՞ք'))
            ->assertOk()
            ->assertJson(['received' => true]);

        $conv = SocialConversation::query()->first();
        $this->assertNotNull($conv);
        $this->assertSame('facebook', $conv->channel);
        $this->assertSame('PSID_1', $conv->psid);
        $this->assertSame(1, $conv->unread_count);
        $this->assertNotNull($conv->last_message_at);

        $this->assertDatabaseHas('social_messages', [
            'external_message_id' => 'mid_1',
            'direction' => 'in',
            'text' => 'Բարև, ազատ սենյակ ունե՞ք',
        ]);
    }

    public function test_duplicate_mid_is_stored_once(): void
    {
        config(['services.meta.app_secret' => '']);

        $payload = $this->messagePayload('PSID_1', 'mid_dup', 'hello');
        $this->postRaw($payload)->assertOk();
        $this->postRaw($payload)->assertOk();

        $this->assertSame(1, SocialMessage::query()->where('external_message_id', 'mid_dup')->count());
        // Unread must not double-count the same delivery.
        $this->assertSame(1, SocialConversation::query()->first()->unread_count);
    }

    public function test_echo_and_non_message_events_are_ignored(): void
    {
        config(['services.meta.app_secret' => '']);

        $echo = [
            'object' => 'page',
            'entry' => [[
                'id' => '112996334711949',
                'messaging' => [
                    // our own outbound (is_echo) — must be skipped
                    ['sender' => ['id' => '112996334711949'], 'message' => ['mid' => 'e1', 'text' => 'reply', 'is_echo' => true]],
                    // a read receipt — no message key — must be skipped
                    ['sender' => ['id' => 'PSID_9'], 'read' => ['watermark' => 1751000000000]],
                ],
            ]],
        ];

        $this->postRaw($echo)->assertOk();

        $this->assertSame(0, SocialMessage::query()->count());
        $this->assertSame(0, SocialConversation::query()->count());
    }

    public function test_inbound_resolves_customer_name_when_page_token_set(): void
    {
        config(['services.meta.app_secret' => '', 'services.meta.page_access_token' => 'PAGETOKEN']);
        Http::fake(['*graph.facebook.com*' => Http::response(['name' => 'Արամ Պետրոսյան', 'id' => 'PSID_N'], 200)]);

        $this->postRaw($this->messagePayload('PSID_N', 'mid_name_1', 'բարև'))->assertOk();

        $this->assertSame(
            'Արամ Պետրոսյան',
            SocialConversation::query()->where('psid', 'PSID_N')->value('customer_name')
        );
    }

    public function test_inbound_name_not_fetched_without_page_token(): void
    {
        config(['services.meta.app_secret' => '', 'services.meta.page_access_token' => '']);
        Http::fake(); // any outbound call would be recorded

        $this->postRaw($this->messagePayload('PSID_NT', 'mid_name_2', 'բարև'))->assertOk();

        $this->assertNull(SocialConversation::query()->where('psid', 'PSID_NT')->value('customer_name'));
        Http::assertNothingSent();
    }

    public function test_signature_enforced_when_secret_present(): void
    {
        config(['services.meta.app_secret' => 'topsecret']);

        $payload = $this->messagePayload('PSID_1', 'mid_sig', 'signed hi');

        // Wrong signature → 403, nothing stored.
        $this->postRaw($payload, 'the-wrong-secret')->assertForbidden();
        $this->assertSame(0, SocialMessage::query()->count());

        // Correct signature → accepted + stored.
        $this->postRaw($payload, 'topsecret')->assertOk();
        $this->assertDatabaseHas('social_messages', ['external_message_id' => 'mid_sig']);
    }
}

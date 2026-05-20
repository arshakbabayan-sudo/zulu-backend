<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\UnsubscribeController;
use App\Models\NewsletterSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Phase 3.2 (GDPR High) — universal unsubscribe link.
 */
class UnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsubscribe_with_valid_token_removes_newsletter(): void
    {
        $user = User::query()->create([
            'name' => 'Unsub User',
            'email' => 'unsub-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        NewsletterSubscription::query()->create([
            'email' => $user->email,
        ]);

        $this->assertTrue(
            NewsletterSubscription::query()->where('email', $user->email)->exists()
        );

        $url = UnsubscribeController::buildUrl($user->id, 'newsletter');
        $token = $this->extractTokenFromUrl($url);

        $res = $this->getJson('/api/unsubscribe?token='.urlencode($token));
        $res->assertOk();
        $res->assertJsonPath('success', true);

        $this->assertFalse(
            NewsletterSubscription::query()->where('email', $user->email)->exists(),
            'Newsletter subscription should be removed'
        );
    }

    public function test_unsubscribe_with_missing_token_returns_400(): void
    {
        $this->getJson('/api/unsubscribe')->assertStatus(400);
    }

    public function test_unsubscribe_with_invalid_token_returns_410(): void
    {
        $this->getJson('/api/unsubscribe?token=garbage')->assertStatus(410);
    }

    public function test_unsubscribe_is_idempotent(): void
    {
        $user = User::query()->create([
            'name' => 'Idem User',
            'email' => 'idem-'.str()->uuid().'@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS_ACTIVE,
        ]);

        $url = UnsubscribeController::buildUrl($user->id, 'newsletter');
        $token = $this->extractTokenFromUrl($url);

        $this->getJson('/api/unsubscribe?token='.urlencode($token))->assertOk();
        // second call: still succeeds (no error if no subscription)
        $this->getJson('/api/unsubscribe?token='.urlencode($token))->assertOk();
    }

    private function extractTokenFromUrl(string $url): string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);

        return (string) ($query['token'] ?? '');
    }
}

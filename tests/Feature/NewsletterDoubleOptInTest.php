<?php

namespace Tests\Feature;

use App\Models\NewsletterSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3.3 (GDPR High) — newsletter double opt-in.
 */
class NewsletterDoubleOptInTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscribe_creates_pending_unconfirmed_row(): void
    {
        $email = 'dbl-'.str()->uuid().'@example.test';
        $res = $this->postJson('/api/newsletter/subscribe', [
            'email' => $email,
            'lang' => 'en',
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.requires_confirmation', true);

        $row = NewsletterSubscription::query()->where('email', $email)->firstOrFail();
        $this->assertNull($row->confirmed_at, 'subscription should start unconfirmed');
        $this->assertNotEmpty($row->confirmation_token, 'should have token');
    }

    public function test_confirm_with_valid_token_sets_confirmed_at(): void
    {
        $email = 'conf-'.str()->uuid().'@example.test';
        $this->postJson('/api/newsletter/subscribe', ['email' => $email, 'lang' => 'en']);

        $row = NewsletterSubscription::query()->where('email', $email)->firstOrFail();
        $token = $row->confirmation_token;

        $res = $this->getJson('/api/newsletter/confirm?token='.$token);
        $res->assertOk();
        $res->assertJsonPath('data.confirmed', true);

        $row->refresh();
        $this->assertNotNull($row->confirmed_at);
        $this->assertNull($row->confirmation_token, 'token should be single-use');
    }

    public function test_confirm_with_invalid_token_returns_410(): void
    {
        $this->getJson('/api/newsletter/confirm?token=invalid')->assertStatus(410);
    }

    public function test_confirm_without_token_returns_400(): void
    {
        $this->getJson('/api/newsletter/confirm')->assertStatus(400);
    }
}

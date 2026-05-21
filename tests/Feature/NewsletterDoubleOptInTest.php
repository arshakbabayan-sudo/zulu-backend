<?php

namespace Tests\Feature;

use App\Mail\NewsletterConfirmationMail;
use App\Models\NewsletterSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 3.3 (GDPR High) — newsletter double opt-in.
 */
class NewsletterDoubleOptInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

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

    public function test_subscribe_queues_confirmation_mail(): void
    {
        $email = 'mail-'.str()->uuid().'@example.test';
        $this->postJson('/api/newsletter/subscribe', ['email' => $email, 'lang' => 'hy']);

        Mail::assertQueued(NewsletterConfirmationMail::class, function (NewsletterConfirmationMail $mail) use ($email) {
            return $mail->hasTo($email)
                && $mail->subscription->lang === 'hy'
                && ! empty($mail->subscription->confirmation_token);
        });
    }

    public function test_resubscribe_after_unsubscribe_queues_fresh_confirmation_mail(): void
    {
        $email = 'resub-'.str()->uuid().'@example.test';
        $row = NewsletterSubscription::query()->create([
            'email' => $email,
            'lang' => 'en',
            'source' => NewsletterSubscription::SOURCE_BOTTOM_FORM,
            'subscribed_at' => now()->subDays(30),
            'unsubscribed_at' => now()->subDays(10),
            'confirmed_at' => now()->subDays(29),
            'confirmation_token' => null,
        ]);

        $this->postJson('/api/newsletter/subscribe', ['email' => $email, 'lang' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.requires_confirmation', true);

        $row->refresh();
        $this->assertNull($row->unsubscribed_at);
        $this->assertNull($row->confirmed_at);
        $this->assertNotEmpty($row->confirmation_token);

        Mail::assertQueued(NewsletterConfirmationMail::class, fn ($mail) => $mail->hasTo($email));
    }

    public function test_resubscribe_when_still_confirmed_does_not_send_mail(): void
    {
        $email = 'noop-'.str()->uuid().'@example.test';
        NewsletterSubscription::query()->create([
            'email' => $email,
            'lang' => 'en',
            'source' => NewsletterSubscription::SOURCE_BOTTOM_FORM,
            'subscribed_at' => now()->subDays(5),
            'confirmed_at' => now()->subDays(5),
            'confirmation_token' => null,
        ]);

        $this->postJson('/api/newsletter/subscribe', ['email' => $email, 'lang' => 'en'])->assertOk();

        Mail::assertNothingQueued();
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

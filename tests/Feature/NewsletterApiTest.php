<?php

namespace Tests\Feature;

use App\Models\NewsletterSubscription;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NewsletterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacBootstrapSeeder::class);
    }

    public function test_subscribe_creates_new_subscription(): void
    {
        $resp = $this->postJson('/api/newsletter/subscribe', [
            'email' => 'arshak@example.am',
            'lang' => 'hy',
            'source' => 'bottom_form',
        ]);

        $resp->assertCreated()->assertJson(['success' => true, 'message' => 'Subscribed']);
        $this->assertDatabaseHas('newsletter_subscriptions', [
            'email' => 'arshak@example.am',
            'lang' => 'hy',
            'source' => 'bottom_form',
        ]);
    }

    public function test_subscribe_validates_email(): void
    {
        $this->postJson('/api/newsletter/subscribe', ['email' => 'not-an-email'])
            ->assertStatus(422);
    }

    public function test_subscribe_rejects_unknown_source(): void
    {
        $this->postJson('/api/newsletter/subscribe', [
            'email' => 'foo@bar.com',
            'source' => 'random_source',
        ])->assertStatus(422);
    }

    public function test_subscribe_is_idempotent_for_same_email_and_lang(): void
    {
        $this->postJson('/api/newsletter/subscribe', ['email' => 'x@y.com', 'lang' => 'en'])->assertCreated();
        $this->postJson('/api/newsletter/subscribe', ['email' => 'x@y.com', 'lang' => 'en'])->assertOk();
        $this->assertSame(1, NewsletterSubscription::query()->where('email', 'x@y.com')->where('lang', 'en')->count());
    }

    public function test_subscribe_resubscribes_after_unsubscribe(): void
    {
        $sub = NewsletterSubscription::query()->create([
            'email' => 'come.back@x.com',
            'lang' => 'en',
            'source' => 'bottom_form',
            'subscribed_at' => now()->subDays(30),
            'unsubscribed_at' => now()->subDays(1),
        ]);

        $this->postJson('/api/newsletter/subscribe', ['email' => 'come.back@x.com', 'lang' => 'en'])->assertOk();
        $this->assertNull($sub->fresh()->unsubscribed_at);
    }

    public function test_admin_index_lists_subscriptions(): void
    {
        $admin = $this->makePlatformAdmin();
        NewsletterSubscription::query()->create([
            'email' => 'a@a.com', 'lang' => 'en', 'source' => 'bottom_form', 'subscribed_at' => now(),
        ]);
        NewsletterSubscription::query()->create([
            'email' => 'b@b.com', 'lang' => 'hy', 'source' => 'middle_form', 'subscribed_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $resp = $this->getJson('/api/platform-admin/newsletter/subscriptions');
        $resp->assertOk();
        $this->assertCount(2, $resp->json('data'));
    }

    public function test_admin_export_csv_returns_attachment(): void
    {
        $admin = $this->makePlatformAdmin();
        NewsletterSubscription::query()->create([
            'email' => 'csv@example.com', 'lang' => 'en', 'source' => 'bottom_form', 'subscribed_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $resp = $this->get('/api/platform-admin/newsletter/subscriptions/export.csv');
        $resp->assertOk();
        $resp->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('csv@example.com', (string) $resp->streamedContent());
    }

    private function makePlatformAdmin(): User
    {
        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}

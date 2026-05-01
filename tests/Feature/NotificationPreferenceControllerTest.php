<?php

namespace Tests\Feature;

use App\Mail\GenericNotificationMail;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationPreferenceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/customer/notification-preferences')->assertStatus(401);
    }

    public function test_index_returns_default_enabled_for_all_pairs(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/customer/notification-preferences');

        $response->assertOk();
        $matrix = $response->json('data');

        $this->assertNotEmpty($matrix);
        // Every entry default true (transactional opt-in).
        foreach ($matrix as $row) {
            $this->assertTrue($row['enabled'], "Default for {$row['event']}/{$row['channel']} must be true.");
        }
    }

    public function test_update_persists_preferences_and_subsequent_index_reflects(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/customer/notification-preferences', [
            'preferences' => [
                ['event' => 'order.paid', 'channel' => 'email', 'enabled' => false],
                ['event' => 'order.paid', 'channel' => 'in_app', 'enabled' => true],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.updated', 2);

        $emailRow = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('event', 'order.paid')
            ->where('channel', 'email')
            ->first();
        $this->assertNotNull($emailRow);
        $this->assertFalse($emailRow->enabled);

        // Subsequent re-fetch shows the override
        $idx = $this->getJson('/api/customer/notification-preferences');
        $optedOut = collect($idx->json('data'))->first(
            fn ($r) => $r['event'] === 'order.paid' && $r['channel'] === 'email'
        );
        $this->assertFalse($optedOut['enabled']);
    }

    public function test_update_validates_event_and_channel(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/customer/notification-preferences', [
            'preferences' => [
                ['event' => 'bogus.event', 'channel' => 'email', 'enabled' => true],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_email_dispatch_skipped_when_user_opts_out(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'opted-out@example.com']);
        UserNotificationPreference::query()->create([
            'user_id' => $user->id,
            'event' => 'order.paid',
            'channel' => 'email',
            'enabled' => false,
        ]);

        (new NotificationService)->createForEventWithEmail([
            'user_id' => $user->id,
            'event_type' => 'order.paid',
            'title' => 'Payment Successful',
            'message' => 'Order ZULU-1 paid.',
            'priority' => 'high',
        ]);

        // In-app notification still created
        $this->assertSame(1, Notification::query()->where('user_id', $user->id)->count());
        // ...but no email queued
        Mail::assertNothingQueued();
    }

    public function test_email_dispatch_proceeds_when_user_has_no_preference_row(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'default@example.com']);

        (new NotificationService)->createForEventWithEmail([
            'user_id' => $user->id,
            'event_type' => 'order.paid',
            'title' => 'Payment Successful',
            'message' => 'Order ZULU-X paid.',
            'priority' => 'high',
        ]);

        Mail::assertQueued(GenericNotificationMail::class);
    }
}

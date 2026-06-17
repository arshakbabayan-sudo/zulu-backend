<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Notifications\FirebasePushProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Exercises the FCM HTTP v1 flow without hitting Google: the OAuth token
 * exchange and the messages:send endpoint are both faked, so we verify the
 * request shapes, token fan-out, and stale-token pruning.
 */
class FirebasePushProviderTest extends TestCase
{
    use RefreshDatabase;

    private function serviceAccount(): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);

        return [
            'client_email' => 'fcm-test@zulu-test.iam.gserviceaccount.com',
            'private_key' => $privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
            'project_id' => 'zulu-test',
        ];
    }

    private function provider(): FirebasePushProvider
    {
        return new FirebasePushProvider('zulu-test', $this->serviceAccount());
    }

    public function test_returns_false_when_user_has_no_tokens(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $this->assertFalse($this->provider()->sendToUser($user->id, 'Hi', 'Body'));
        Http::assertNothingSent();
    }

    public function test_sends_v1_message_to_each_token(): void
    {
        $user = User::factory()->create();
        DeviceToken::query()->create(['user_id' => $user->id, 'token' => 'dev-A', 'platform' => 'web']);
        DeviceToken::query()->create(['user_id' => $user->id, 'token' => 'dev-B', 'platform' => 'android']);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/zulu-test/messages/1']),
        ]);

        $ok = $this->provider()->sendToUser($user->id, 'Trip confirmed', 'Your booking is paid');

        $this->assertTrue($ok);
        // One token exchange + one send per device.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'oauth2.googleapis.com/token'));
        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'projects/zulu-test/messages:send')
                && ($req['message']['notification']['title'] ?? null) === 'Trip confirmed'
                && in_array($req['message']['token'] ?? null, ['dev-A', 'dev-B'], true);
        });
    }

    public function test_prunes_unregistered_token(): void
    {
        $user = User::factory()->create();
        DeviceToken::query()->create(['user_id' => $user->id, 'token' => 'dead-tok', 'platform' => 'web']);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNREGISTERED']], 404),
        ]);

        $ok = $this->provider()->sendToUser($user->id, 'Hi', 'Body');

        $this->assertFalse($ok);
        $this->assertDatabaseMissing('device_tokens', ['token' => 'dead-tok']);
    }
}

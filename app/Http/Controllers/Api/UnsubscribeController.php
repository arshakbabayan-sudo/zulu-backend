<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscription;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3.2 (GDPR High) — universal unsubscribe endpoint.
 *
 * Every marketing-class Mailable footer carries a signed unsubscribe URL
 * of the form:
 *
 *   https://api.zulu.am/api/unsubscribe?token=<encrypted-payload>
 *
 * The payload is `Crypt::encryptString(json_encode([
 *     "user_id" => $userId,
 *     "channel" => "newsletter|promotional|all",
 *     "issued_at" => unix_timestamp,
 * ]))` — tamper-proof, single-purpose, no DB write needed at issue time.
 *
 * On click, the link decrypts the payload and either:
 *   - Removes the user from newsletter_subscriptions, OR
 *   - Sets UserNotificationPreference rows to opt-out for the channel
 *
 * Idempotent — clicking twice is fine.
 */
class UnsubscribeController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $token = (string) $request->query('token', '');
        if ($token === '') {
            return response()->json(['success' => false, 'message' => 'Missing token.'], 400);
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired link.'], 410);
        }

        $userId = (int) ($payload['user_id'] ?? 0);
        $channel = (string) ($payload['channel'] ?? 'all');

        if ($userId <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid token.'], 410);
        }

        $user = User::query()->find($userId);
        $userEmail = $user?->email;

        DB::transaction(function () use ($userId, $userEmail, $channel): void {
            // Remove from newsletter list if applicable.
            if (in_array($channel, ['newsletter', 'all'], true) && $userEmail !== null) {
                NewsletterSubscription::query()
                    ->where('email', $userEmail)
                    ->delete();
            }

            // Opt out of email notification preferences for the channel.
            // user_notification_preferences schema: (user_id, event, channel) UNIQUE.
            if (DB::getSchemaBuilder()->hasTable('user_notification_preferences')) {
                $events = $channel === 'all'
                    ? ['newsletter', 'promotional', 'transactional']
                    : [$channel];

                foreach ($events as $event) {
                    UserNotificationPreference::query()->updateOrCreate(
                        ['user_id' => $userId, 'event' => $event, 'channel' => 'email'],
                        ['enabled' => false],
                    );
                }
            }
        });

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'You have been unsubscribed.',
                'channel' => $channel,
            ],
        ]);
    }

    /**
     * Static helper — build the signed unsubscribe URL for a given user + channel.
     * Mailable templates inject this URL into the footer.
     */
    public static function buildUrl(int $userId, string $channel = 'newsletter'): string
    {
        $payload = json_encode([
            'user_id' => $userId,
            'channel' => $channel,
            'issued_at' => time(),
        ]);
        $token = Crypt::encryptString($payload);
        $base = rtrim((string) config('app.url'), '/');

        return $base.'/api/unsubscribe?token='.urlencode($token);
    }
}

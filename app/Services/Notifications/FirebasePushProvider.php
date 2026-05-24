<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging push provider. Activated by AppServiceProvider
 * when config('services.firebase.project_id') is set.
 *
 * Implementation note: this is the HTTP v1 endpoint shape but the OAuth
 * token retrieval (service-account → access token) is deferred until the
 * user lands real credentials. For now we expect a `server_key` (legacy
 * FCM) in config to keep the binding simple. If only `project_id` is
 * present without `server_key`, send() throws to surface setup error.
 *
 * Device token lookup is also placeholder: we read from a hypothetical
 * `device_tokens` JSON column on users — if the column is missing we
 * soft-fail and log. Schema for device tokens will land with real FCM.
 */
class FirebasePushProvider implements PushProvider
{
    public function __construct(
        private readonly string $projectId,
        private readonly ?string $serverKey,
    ) {}

    public function sendToUser(int $userId, string $title, string $body): bool
    {
        if ($this->projectId === '' || $this->serverKey === null || $this->serverKey === '') {
            throw new RuntimeException('FirebasePushProvider is missing project_id or server_key.');
        }

        $tokens = $this->resolveDeviceTokens($userId);
        if ($tokens === []) {
            Log::info('FirebasePushProvider: no device tokens on file', [
                'user_id' => $userId,
            ]);

            return false;
        }

        try {
            $allOk = true;
            foreach ($tokens as $token) {
                $response = Http::withHeaders([
                    'Authorization' => 'key='.$this->serverKey,
                    'Content-Type' => 'application/json',
                ])->post('https://fcm.googleapis.com/fcm/send', [
                    'to' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                ]);

                if (! $response->successful()) {
                    $allOk = false;
                    Log::warning('FCM push send failed', [
                        'user_id' => $userId,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            }

            return $allOk;
        } catch (\Throwable $e) {
            Log::warning('FCM push exception', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** @return list<string> */
    private function resolveDeviceTokens(int $userId): array
    {
        // Placeholder lookup — when device_tokens infrastructure ships
        // this becomes a real query. Until then we cleanly return [].
        return [];
    }
}

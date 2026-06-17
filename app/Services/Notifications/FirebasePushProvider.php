<?php

namespace App\Services\Notifications;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging push provider — HTTP v1 API.
 *
 * Authenticated with a Google service account (the legacy `server_key` / fcm/send
 * endpoint was removed by Google in 2024). We mint a short-lived OAuth2 access
 * token by signing a JWT with the service account private key, cache it, and send
 * each message to https://fcm.googleapis.com/v1/projects/{projectId}/messages:send.
 *
 * Bound by AppServiceProvider when a service-account JSON is configured
 * (services.firebase.credentials path or services.firebase.credentials_json).
 * Device tokens come from the `device_tokens` table; tokens FCM reports as
 * unregistered are pruned so we stop pushing to dead devices.
 */
class FirebasePushProvider implements PushProvider
{
    private const TOKEN_CACHE_KEY = 'fcm_access_token';
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * @param  array<string, mixed>  $serviceAccount  decoded service-account JSON
     */
    public function __construct(
        private readonly string $projectId,
        private readonly array $serviceAccount,
    ) {}

    public function sendToUser(int $userId, string $title, string $body): bool
    {
        $tokens = $this->resolveDeviceTokens($userId);
        if ($tokens === []) {
            Log::info('FirebasePushProvider: no device tokens on file', ['user_id' => $userId]);

            return false;
        }

        try {
            $accessToken = $this->accessToken();
        } catch (\Throwable $e) {
            Log::warning('FCM access-token mint failed', ['message' => $e->getMessage()]);

            return false;
        }

        if ($accessToken === null) {
            return false;
        }

        $endpoint = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
        $allOk = true;

        foreach ($tokens as $token) {
            try {
                $response = Http::withToken($accessToken)
                    ->asJson()
                    ->post($endpoint, [
                        'message' => [
                            'token' => $token,
                            'notification' => [
                                'title' => $title,
                                'body' => $body,
                            ],
                        ],
                    ]);

                if ($response->successful()) {
                    continue;
                }

                $allOk = false;

                // FCM reports a dead/rotated token as 404 NOT_FOUND or 400
                // UNREGISTERED — prune it so we stop trying.
                $status = $response->status();
                $errStatus = (string) $response->json('error.status', '');
                if ($status === 404 || $errStatus === 'UNREGISTERED' || $errStatus === 'NOT_FOUND') {
                    DeviceToken::query()->where('token', $token)->delete();
                }

                Log::warning('FCM v1 push send failed', [
                    'user_id' => $userId,
                    'status' => $status,
                    'error' => $errStatus,
                ]);
            } catch (\Throwable $e) {
                $allOk = false;
                Log::warning('FCM v1 push exception', [
                    'user_id' => $userId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $allOk;
    }

    /** @return list<string> */
    private function resolveDeviceTokens(int $userId): array
    {
        return DeviceToken::query()
            ->where('user_id', $userId)
            ->pluck('token')
            ->all();
    }

    /**
     * Mint (and cache) an OAuth2 access token for the FCM scope by signing a
     * JWT with the service-account private key and exchanging it at the Google
     * token endpoint. Cached just under the 1-hour lifetime.
     */
    private function accessToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $clientEmail = (string) ($this->serviceAccount['client_email'] ?? '');
        $privateKey = (string) ($this->serviceAccount['private_key'] ?? '');
        $tokenUri = (string) ($this->serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        if ($clientEmail === '' || $privateKey === '') {
            return null;
        }

        $now = time();
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url(json_encode([
            'iss' => $clientEmail,
            'scope' => self::SCOPE,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $signingInput = $header.'.'.$claims;

        $signature = '';
        if (! openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            Log::warning('FCM JWT signing failed (bad private key?)');

            return null;
        }
        $jwt = $signingInput.'.'.$this->base64Url($signature);

        $response = Http::asForm()->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (! $response->successful()) {
            Log::warning('FCM token exchange failed', ['status' => $response->status()]);

            return null;
        }

        $accessToken = (string) $response->json('access_token', '');
        $expiresIn = (int) $response->json('expires_in', 3600);
        if ($accessToken === '') {
            return null;
        }

        Cache::put(self::TOKEN_CACHE_KEY, $accessToken, max(60, $expiresIn - 60));

        return $accessToken;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

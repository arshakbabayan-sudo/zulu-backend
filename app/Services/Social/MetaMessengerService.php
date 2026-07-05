<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to Meta's Graph API for the social inbox:
 *  - sendText()        : send a reply to a customer's Messenger (page token)
 *  - fetchProfileName(): resolve a PSID to the person's display name
 *  - wireWebhook()     : point the app's webhook at us + subscribe the page
 *                        (idempotent — safe to run on every deploy)
 *
 * All credentials come from config('services.meta.*') (server .env, never
 * committed). Every method is defensive: a Graph API hiccup logs + returns a
 * failure shape rather than throwing, so it can't 500 the webhook or break a
 * deploy.
 */
class MetaMessengerService
{
    private function base(): string
    {
        $version = (string) (config('services.meta.graph_version') ?: 'v21.0');

        return "https://graph.facebook.com/{$version}";
    }

    public function hasPageToken(): bool
    {
        return (string) config('services.meta.page_access_token') !== '';
    }

    /**
     * Send a text reply to a Messenger user (within the 24h window,
     * messaging_type=RESPONSE). Returns ['success'=>bool, 'error'=>?string].
     *
     * @return array{success: bool, error?: string, message_id?: string}
     */
    public function sendText(string $psid, string $text): array
    {
        $token = (string) config('services.meta.page_access_token');
        if ($token === '') {
            return ['success' => false, 'error' => 'page access token not configured'];
        }

        try {
            $res = Http::asJson()
                ->timeout(15)
                ->post($this->base().'/me/messages', [
                    'recipient' => ['id' => $psid],
                    'messaging_type' => 'RESPONSE',
                    'message' => ['text' => $text],
                    'access_token' => $token,
                ]);
        } catch (\Throwable $e) {
            Log::error('Meta sendText transport error', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }

        if (! $res->successful()) {
            Log::warning('Meta sendText failed', ['status' => $res->status(), 'body' => $res->body()]);

            return ['success' => false, 'error' => (string) $res->json('error.message', 'send failed')];
        }

        return ['success' => true, 'message_id' => (string) $res->json('message_id', '')];
    }

    /**
     * Resolve a PSID to a display name. Returns null on any failure (name is
     * best-effort chrome; the conversation still works without it).
     */
    public function fetchProfileName(string $psid): ?string
    {
        $token = (string) config('services.meta.page_access_token');
        if ($token === '') {
            return null;
        }

        try {
            $res = Http::timeout(10)->get($this->base()."/{$psid}", [
                'fields' => 'name',
                'access_token' => $token,
            ]);
        } catch (\Throwable $e) {
            Log::info('Meta fetchProfileName transport error', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $res->successful()) {
            return null;
        }
        $name = trim((string) $res->json('name', ''));

        return $name !== '' ? $name : null;
    }

    /**
     * Point the app's webhook at our endpoint + subscribe the page. Idempotent.
     * Returns a per-step summary for logging.
     *
     * @return array<string, mixed>
     */
    public function wireWebhook(): array
    {
        $appId = (string) config('services.meta.app_id');
        $appSecret = (string) config('services.meta.app_secret');
        $verifyToken = (string) config('services.meta.verify_token');
        $pageId = (string) config('services.meta.page_id');
        $pageToken = (string) config('services.meta.page_access_token');
        $callback = rtrim((string) config('app.url'), '/').'/api/webhooks/meta';
        $fields = 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads';

        $out = ['callback_url' => $callback];

        if ($appId === '' || $appSecret === '' || $verifyToken === '') {
            $out['app_subscription'] = 'skipped — app_id/app_secret/verify_token missing';
        } else {
            try {
                $res = Http::asForm()->timeout(15)->post($this->base()."/{$appId}/subscriptions", [
                    'object' => 'page',
                    'callback_url' => $callback,
                    'verify_token' => $verifyToken,
                    'fields' => $fields,
                    'include_values' => 'true',
                    'access_token' => $appId.'|'.$appSecret,
                ]);
                $out['app_subscription'] = $res->successful() ? 'ok' : 'FAILED: '.$res->body();
            } catch (\Throwable $e) {
                $out['app_subscription'] = 'ERROR: '.$e->getMessage();
            }
        }

        if ($pageId === '' || $pageToken === '') {
            $out['page_subscription'] = 'skipped — page_id/page_token missing';
        } else {
            try {
                $res = Http::asForm()->timeout(15)->post($this->base()."/{$pageId}/subscribed_apps", [
                    'subscribed_fields' => $fields,
                    'access_token' => $pageToken,
                ]);
                $out['page_subscription'] = $res->successful() ? 'ok' : 'FAILED: '.$res->body();
            } catch (\Throwable $e) {
                $out['page_subscription'] = 'ERROR: '.$e->getMessage();
            }
        }

        Log::info('Meta wireWebhook result', $out);

        return $out;
    }
}

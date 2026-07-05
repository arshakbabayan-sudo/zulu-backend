<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Social\SocialInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Meta (Facebook Messenger / Instagram Direct) webhook.
 *
 * Two verbs on the SAME public URL (POST /webhooks/meta), exactly as Meta's
 * Webhooks product requires:
 *   - GET  → the one-time subscription handshake. Meta calls with
 *            hub.mode=subscribe, hub.verify_token=<our token>, hub.challenge=<n>.
 *            We echo the challenge verbatim iff the token matches.
 *   - POST → a delivery. Signed with X-Hub-Signature-256 (HMAC-SHA256 of the
 *            raw body using the app secret). We verify, then store each inbound
 *            message and ALWAYS answer 200 quickly (Meta disables webhooks that
 *            are slow or error — processing failures must not surface as non-200).
 *
 * Public + unauthenticated by design: Meta calls it with no ZULU session. The
 * signature (once META_APP_SECRET is set) is what authenticates the caller.
 */
class MetaWebhookController extends Controller
{
    public function __construct(
        private readonly SocialInboxService $inbox,
        private readonly \App\Services\Social\MetaMessengerService $messenger,
    ) {
    }

    /** GET /webhooks/meta — subscription handshake. */
    public function verify(Request $request): Response
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        $expected = (string) config('services.meta.verify_token');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            // Must be the raw challenge as text/plain, status 200.
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('Meta webhook verification rejected', ['mode' => $mode]);

        return response('Forbidden', 403);
    }

    /** POST /webhooks/meta — message delivery. */
    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (! $this->signatureValid($request, $raw)) {
            Log::warning('Meta webhook signature invalid');

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        try {
            $this->ingest(json_decode($raw, true) ?: []);
        } catch (\Throwable $e) {
            // Accepted-but-failed: log and still 200 so Meta does not retry
            // forever / disable the subscription.
            Log::error('Meta webhook processing error', ['error' => $e->getMessage()]);
        }

        return response()->json(['received' => true], 200);
    }

    /**
     * Verify X-Hub-Signature-256. When META_APP_SECRET is unset (initial wiring,
     * before the secret is provisioned) we accept and log once so the pipe can
     * be smoke-tested; once the secret is present the check is enforced.
     */
    private function signatureValid(Request $request, string $raw): bool
    {
        $secret = (string) config('services.meta.app_secret');
        if ($secret === '') {
            Log::info('Meta webhook: no app secret set, skipping signature check');

            return true;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);

        return hash_equals($expected, $header);
    }

    /**
     * Walk the webhook payload and store every inbound customer message.
     *
     * @param  array<string, mixed>  $payload
     */
    private function ingest(array $payload): void
    {
        $object = (string) ($payload['object'] ?? '');
        // 'page' = Messenger, 'instagram' = Instagram Direct. Anything else
        // (feed changes, page changes) is not a message — ignore.
        if (! in_array($object, ['page', 'instagram'], true)) {
            return;
        }
        $channel = $object === 'instagram' ? 'instagram' : 'facebook';

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            $pageId = (string) ($entry['id'] ?? '');
            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                $message = $event['message'] ?? null;
                if (! is_array($message)) {
                    // postbacks / read receipts / deliveries — skip for now.
                    continue;
                }
                // is_echo = a message the PAGE sent (our own reply or a message
                // from the page inbox). We record our outbound separately.
                if (! empty($message['is_echo'])) {
                    continue;
                }

                $psid = (string) ($event['sender']['id'] ?? '');
                if ($psid === '' || $psid === $pageId) {
                    continue;
                }

                $attachments = null;
                if (! empty($message['attachments']) && is_array($message['attachments'])) {
                    $attachments = array_map(static function ($a): array {
                        return [
                            'type' => $a['type'] ?? 'unknown',
                            'url' => $a['payload']['url'] ?? null,
                        ];
                    }, $message['attachments']);
                }

                $stored = $this->inbox->recordInbound(
                    channel: $channel,
                    pageId: $pageId,
                    psid: $psid,
                    externalMessageId: isset($message['mid']) ? (string) $message['mid'] : null,
                    text: isset($message['text']) ? (string) $message['text'] : null,
                    attachments: $attachments,
                    raw: $event,
                    timestampMs: isset($event['timestamp']) ? (int) $event['timestamp'] : null,
                );

                // First message from a new person → resolve their display name
                // from the Graph API (best-effort; skipped if no page token).
                $conversation = $stored?->conversation;
                if ($conversation !== null
                    && ($conversation->customer_name === null || $conversation->customer_name === '')
                    && $this->messenger->hasPageToken()) {
                    $name = $this->messenger->fetchProfileName($psid);
                    if ($name !== null) {
                        $conversation->forceFill(['customer_name' => $name])->save();
                    }
                }
            }
        }
    }
}

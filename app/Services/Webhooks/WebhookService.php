<?php

namespace App\Services\Webhooks;

use App\Models\Company;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * PART 30 — Webhook subscriptions + dispatch with HMAC signing.
 */
class WebhookService
{
    public const MAX_ATTEMPTS = 5;

    public const REQUEST_TIMEOUT = 10; // seconds

    /**
     * Register a new subscription for a Seller company.
     *
     * @param  array<string, mixed>  $payload  target_url, events[], description, active
     */
    public function subscribe(Company $company, array $payload): WebhookSubscription
    {
        $url = (string) ($payload['target_url'] ?? '');
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid target_url.');
        }

        $events = $payload['events'] ?? [];
        if (! is_array($events) || $events === []) {
            throw new InvalidArgumentException('At least one event required.');
        }
        foreach ($events as $event) {
            if (! in_array($event, WebhookSubscription::SUPPORTED_EVENTS, true)) {
                throw new InvalidArgumentException("Unsupported event: {$event}");
            }
        }

        return WebhookSubscription::query()->create([
            'company_id' => $company->id,
            'target_url' => $url,
            'secret' => bin2hex(random_bytes(32)), // 64-char hex
            'events' => $events,
            'description' => $payload['description'] ?? null,
            'active' => (bool) ($payload['active'] ?? true),
        ]);
    }

    /**
     * Update a subscription in place (admin/seller edit). Validates the same
     * invariants subscribe() does; the secret is never touched.
     *
     * @param  array<string, mixed>  $payload
     */
    public function update(WebhookSubscription $subscription, array $payload): WebhookSubscription
    {
        if (array_key_exists('target_url', $payload)) {
            $url = (string) $payload['target_url'];
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException('Invalid target_url.');
            }
            $subscription->target_url = $url;
        }
        if (array_key_exists('events', $payload)) {
            $events = $payload['events'];
            if (! is_array($events) || $events === []) {
                throw new InvalidArgumentException('At least one event required.');
            }
            foreach ($events as $event) {
                if (! in_array($event, WebhookSubscription::SUPPORTED_EVENTS, true)) {
                    throw new InvalidArgumentException("Unsupported event: {$event}");
                }
            }
            $subscription->events = $events;
        }
        if (array_key_exists('description', $payload)) {
            $subscription->description = $payload['description'];
        }
        if (array_key_exists('active', $payload)) {
            $subscription->active = (bool) $payload['active'];
        }
        $subscription->save();

        return $subscription;
    }

    public function unsubscribe(Company $company, int $id): bool
    {
        $sub = WebhookSubscription::query()
            ->where('company_id', $company->id)
            ->find($id);
        if ($sub === null) {
            return false;
        }

        return (bool) $sub->delete();
    }

    /**
     * Dispatch an event to all subscribed webhooks for any company.
     * Synchronous in MVP — production should queue.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $event, array $payload): int
    {
        $subscriptions = WebhookSubscription::query()
            ->where('active', true)
            ->get()
            ->filter(fn (WebhookSubscription $s) => $s->subscribesTo($event));

        $dispatched = 0;
        foreach ($subscriptions as $sub) {
            $this->deliver($sub, $event, $payload);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Deliver one event to one subscription. Records WebhookDelivery row.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliver(WebhookSubscription $subscription, string $event, array $payload): WebhookDelivery
    {
        $idempotencyKey = substr(hash('sha256', $subscription->id.'|'.$event.'|'.json_encode($payload).'|'.microtime(true)), 0, 64);

        return DB::transaction(function () use ($subscription, $event, $payload, $idempotencyKey): WebhookDelivery {
            $delivery = WebhookDelivery::query()->create([
                'subscription_id' => $subscription->id,
                'event' => $event,
                'idempotency_key' => $idempotencyKey,
                'payload' => $payload,
                'status' => 'pending',
                'attempt_count' => 0,
            ]);

            $this->attempt($delivery);

            return $delivery->fresh();
        });
    }

    public function attempt(WebhookDelivery $delivery): WebhookDelivery
    {
        $subscription = $delivery->subscription;
        if ($subscription === null) {
            $delivery->status = 'failed';
            $delivery->error_message = 'Subscription missing.';
            $delivery->save();

            return $delivery;
        }

        $body = json_encode([
            'event' => $delivery->event,
            'idempotency_key' => $delivery->idempotency_key,
            'sent_at' => now()->toIso8601String(),
            'data' => $delivery->payload,
        ], JSON_UNESCAPED_UNICODE);

        $signature = hash_hmac('sha256', (string) $body, $subscription->secret);

        $delivery->attempt_count = ($delivery->attempt_count ?? 0) + 1;
        $delivery->last_attempted_at = now();
        if ($delivery->first_attempted_at === null) {
            $delivery->first_attempted_at = now();
        }

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->withHeaders([
                    'X-Zulu-Event' => $delivery->event,
                    'X-Zulu-Signature' => 'sha256='.$signature,
                    'X-Zulu-Idempotency-Key' => $delivery->idempotency_key,
                    'Content-Type' => 'application/json',
                ])
                ->withBody((string) $body, 'application/json')
                ->post($subscription->target_url);

            $delivery->http_status = $response->status();
            $delivery->response_excerpt = substr((string) $response->body(), 0, 1000);

            if ($response->successful()) {
                $delivery->status = 'success';
                $delivery->succeeded_at = now();
                $subscription->last_succeeded_at = now();
                $subscription->failure_count = 0;
                $subscription->save();
            } else {
                $delivery->status = $delivery->attempt_count >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
                $delivery->error_message = "HTTP {$response->status()}";
                $subscription->last_failed_at = now();
                $subscription->failure_count = $subscription->failure_count + 1;
                $subscription->save();
            }
        } catch (Throwable $e) {
            Log::warning('Webhook delivery threw', [
                'subscription_id' => $subscription->id,
                'event' => $delivery->event,
                'message' => $e->getMessage(),
            ]);
            $delivery->error_message = $e->getMessage();
            $delivery->status = $delivery->attempt_count >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
            $subscription->last_failed_at = now();
            $subscription->failure_count = $subscription->failure_count + 1;
            $subscription->save();
        }

        $delivery->save();

        return $delivery->fresh();
    }

    /**
     * Verify an incoming signature header matches a payload + secret.
     */
    public function verifySignature(string $payload, string $secret, string $signatureHeader): bool
    {
        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $provided = substr($signatureHeader, 7);
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $provided);
    }
}

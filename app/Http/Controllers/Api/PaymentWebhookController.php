<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Services\Payments\PaymentGatewayService;
use App\Services\Payments\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    /**
     * Stripe-only legacy entrypoint preserved at POST /payments/webhook
     * so the existing Stripe Dashboard URL keeps working without a
     * reconfiguration round-trip.
     */
    public function handle(
        Request $request,
        PaymentGatewayService $gatewayService,
        PaymentService $paymentService
    ): JsonResponse {
        return $this->dispatchTo('stripe', $request, $gatewayService, $paymentService);
    }

    /**
     * Driver-aware entrypoint at POST /payments/webhook/{driver}.
     * Same logic, but selects the gateway from the URL segment so a
     * single deploy can receive callbacks from Stripe, ArCa and Idram
     * simultaneously.
     */
    public function handleForDriver(
        string $driver,
        Request $request,
        PaymentGatewayService $gatewayService,
        PaymentService $paymentService
    ): JsonResponse {
        $driver = strtolower(trim($driver));
        if (! in_array($driver, PaymentGatewayService::SUPPORTED_DRIVERS, true)) {
            return response()->json(['error' => 'Unsupported driver'], 404);
        }

        return $this->dispatchTo($driver, $request, $gatewayService, $paymentService);
    }

    private function dispatchTo(
        string $driver,
        Request $request,
        PaymentGatewayService $gatewayService,
        PaymentService $paymentService
    ): JsonResponse {
        $payload = $request->getContent();
        $headers = $this->flattenHeaders($request);

        $service = $gatewayService->usingDriver($driver);

        $constructed = $service->constructWebhookEvent(
            $payload,
            // Stripe path still passes a single header string for backward
            // compat; the router unwraps it. For ArCa/Idram the gateway
            // reads the full header bag from the request below.
            (string) ($headers['stripe-signature'] ?? '')
        );

        // ArCa/Idram drivers ignore Stripe-Signature and verify the
        // body's own checksum, so the result is still correct — we just
        // detected it via the unified API. For full header access the
        // driver could be invoked directly; left as a follow-up.

        if (! ($constructed['success'] ?? false)) {
            Log::warning('Payment webhook signature invalid', [
                'driver' => $driver,
                'error' => $constructed['error'] ?? 'unknown',
            ]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $eventType = (string) ($constructed['event_type']
            ?? ($constructed['event']->type ?? '') // Stripe back-compat shape
        );
        $reference = (string) ($constructed['gateway_reference']
            ?? ($constructed['event']->data->object->id ?? '')
        );

        try {
            $this->processEvent($driver, $eventType, $reference, $constructed, $request, $paymentService);
        } catch (\Throwable $e) {
            Log::error('Payment webhook handler error', [
                'driver' => $driver,
                'error' => $e->getMessage(),
                'event_type' => $eventType,
            ]);
        }

        return response()->json(['received' => true], 200);
    }

    /**
     * @param  array<string, mixed>  $constructed
     */
    private function processEvent(
        string $driver,
        string $eventType,
        string $reference,
        array $constructed,
        Request $request,
        PaymentService $paymentService
    ): void {
        // P0-1 step 1.4 — Stripe Connect account.updated lives on the same
        // webhook endpoint as our regular PaymentIntent events. The
        // `reference` here is the connected account id (acct_…), not a
        // payment_intent id, so we branch BEFORE the Payment lookup.
        if ($driver === 'stripe' && $eventType === 'account.updated') {
            $this->handleStripeAccountUpdated($reference, $constructed, $request);

            return;
        }

        // Normalize each gateway's event vocabulary to a uniform set:
        //   payment.succeeded | payment.failed | refund.succeeded | other
        $normalized = $this->normalizeEventType($driver, $eventType);

        if ($normalized === 'other' || $reference === '') {
            Log::info('Payment webhook event ignored', [
                'driver' => $driver,
                'event_type' => $eventType,
                'reference' => $reference,
            ]);

            return;
        }

        $payment = Payment::query()->where('reference_code', $reference)->first();
        if ($payment === null) {
            Log::info('Payment webhook for unknown reference', [
                'driver' => $driver,
                'reference' => $reference,
            ]);

            return;
        }

        $rawPayload = $constructed['raw'] ?? $constructed['event'] ?? null;
        $payloadArray = is_object($rawPayload) && method_exists($rawPayload, 'toArray')
            ? $rawPayload->toArray()
            : (is_array($rawPayload) ? $rawPayload : null);

        // Audit C3 — resolve the idempotency key for THIS event. Prefer the
        // gateway's stable event id (Stripe evt_…, Idram EDP_TRANS_ID), which is
        // resent unchanged on every retry. When the gateway has no per-event id
        // (ArCa, or a malformed payload), fall back to a composite token so we
        // still get a non-null, gateway-scoped dedupe key.
        $idempotencyKey = $this->resolveIdempotencyKey($constructed, $reference, $normalized);

        $logType = match ($normalized) {
            'payment.succeeded' => 'webhook.payment_intent.succeeded',
            'payment.failed' => 'webhook.payment_intent.failed',
            'refund.succeeded' => 'webhook.refund.succeeded',
            default => 'webhook.'.$normalized,
        };
        $logStatus = match ($normalized) {
            'payment.succeeded' => 'succeeded',
            'payment.failed' => 'failed',
            'refund.succeeded' => 'refunded',
            default => null,
        };

        try {
            DB::transaction(function () use (
                $driver,
                $reference,
                $idempotencyKey,
                $normalized,
                $payment,
                $payloadArray,
                $logType,
                $logStatus,
                $request,
                $paymentService
            ): void {
                // (1) INSERT the idempotency-token row FIRST, inside the
                // transaction. The partial UNIQUE index on
                // (gateway, gateway_event_id) makes a SECOND delivery of the
                // SAME event fail here atomically — caught below as "already
                // processed" so side-effects can't run twice, even across
                // separate worker processes.
                PaymentLog::query()->create([
                    'payment_id' => $payment->id,
                    'event_type' => $logType,
                    'gateway' => $driver,
                    'gateway_reference' => $reference,
                    'gateway_event_id' => $idempotencyKey,
                    'status' => $logStatus,
                    'response_payload' => $payloadArray,
                    'ip_address' => $request->ip(),
                ]);

                // (2) Re-fetch the Payment WITH a row lock and re-check status
                // INSIDE the lock. This is the second line of defense: it covers
                // gateways whose idempotency key is weaker (ArCa composite) and
                // serializes concurrent transitions on the same payment row.
                $locked = Payment::query()->lockForUpdate()->find($payment->id) ?? $payment;

                if ($normalized === 'payment.succeeded') {
                    if ($locked->status !== Payment::STATUS_PAID) {
                        // markPaid is itself a no-op when already paid (audit C3),
                        // so side-effects fire exactly once.
                        $paymentService->markPaid($locked);
                    }

                    return;
                }

                if ($normalized === 'payment.failed') {
                    if ($locked->status === Payment::STATUS_PENDING) {
                        $paymentService->markFailed($locked);
                    }

                    return;
                }

                // refund.succeeded — the log row IS the side-effect (the refund
                // itself is applied via the admin refund flow / gateway). Nothing
                // further to do; the unique key still prevents a duplicate log.
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                // SECOND delivery of an already-processed event. Treat as
                // success so the gateway stops retrying; do NOT re-run any
                // side-effects. NEVER let this surface as a 500 on the live
                // payment path.
                Log::info('Payment webhook duplicate ignored (already processed)', [
                    'driver' => $driver,
                    'reference' => $reference,
                    'gateway_event_id' => $idempotencyKey,
                    'event' => $normalized,
                ]);

                return;
            }

            throw $e;
        }
    }

    /**
     * Resolve a stable, gateway-scoped idempotency token for this event.
     * Prefers the gateway's own event id; falls back to a composite of the
     * gateway_reference + normalized event type so the key is never null.
     *
     * @param  array<string, mixed>  $constructed
     */
    private function resolveIdempotencyKey(array $constructed, string $reference, string $normalized): string
    {
        $eventId = trim((string) ($constructed['event_id'] ?? ''));
        if ($eventId !== '') {
            return $eventId;
        }

        return $reference.':'.$normalized;
    }

    /**
     * Detect a unique-constraint violation across both supported engines:
     * Postgres SQLSTATE 23505, SQLite 23000.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

        if ($sqlState === '23505' || $sqlState === '23000') {
            return true;
        }

        // Belt-and-braces: some SQLite builds surface the message rather than a
        // clean SQLSTATE on the errorInfo tuple.
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique constraint')
            || str_contains($message, 'unique violation')
            || str_contains($message, 'duplicate key');
    }

    /**
     * P0-1 step 1.4 — Stripe Connect Express status sync via webhook.
     *
     * When a seller's connected account state changes on Stripe's side
     * (finished onboarding, KYC approved, payouts unlocked, capability
     * rejected, etc.) Stripe pings us with account.updated. We mirror the
     * 3 booleans onto the matching `companies` row so the admin Payments
     * card flips from pending → ready without the user reopening it.
     *
     * @param  array<string, mixed>  $constructed
     */
    private function handleStripeAccountUpdated(
        string $accountId,
        array $constructed,
        Request $request
    ): void {
        if ($accountId === '') {
            return;
        }

        $company = Company::query()->where('stripe_connect_id', $accountId)->first();
        if ($company === null) {
            Log::info('Stripe account.updated for unknown company', [
                'stripe_account_id' => $accountId,
            ]);

            return;
        }

        $raw = $constructed['raw'] ?? $constructed['event'] ?? null;
        $object = is_object($raw)
            ? ($raw->data->object ?? null)
            : (is_array($raw) ? ($raw['data']['object'] ?? null) : null);
        $payloadArray = is_object($object) && method_exists($object, 'toArray')
            ? $object->toArray()
            : (is_array($object) ? $object : null);

        $chargesEnabled = $this->readBool($object, 'charges_enabled');
        $payoutsEnabled = $this->readBool($object, 'payouts_enabled');
        $detailsSubmitted = $this->readBool($object, 'details_submitted');

        $company->forceFill([
            'stripe_charges_enabled' => $chargesEnabled,
            'stripe_payouts_enabled' => $payoutsEnabled,
            'stripe_details_submitted' => $detailsSubmitted,
        ])->save();

        PaymentLog::query()->create([
            'payment_id' => null,
            'event_type' => 'webhook.connect.account.updated',
            'gateway' => 'stripe',
            'gateway_reference' => $accountId,
            'response_payload' => array_merge(
                ['zulu_company_id' => $company->id],
                is_array($payloadArray) ? $payloadArray : []
            ),
            'ip_address' => $request->ip(),
        ]);
    }

    /**
     * Read a boolean field from a Stripe object (StripeObject or array)
     * with safe default of false. Handles both the SDK's typed object and
     * the array fallback we use when the SDK wasn't able to deserialise.
     */
    private function readBool(mixed $object, string $key): bool
    {
        if (is_object($object)) {
            return (bool) ($object->{$key} ?? false);
        }
        if (is_array($object)) {
            return (bool) ($object[$key] ?? false);
        }

        return false;
    }

    private function normalizeEventType(string $driver, string $eventType): string
    {
        return match ($driver.':'.$eventType) {
            'stripe:payment_intent.succeeded' => 'payment.succeeded',
            'stripe:payment_intent.payment_failed' => 'payment.failed',
            'stripe:charge.refunded' => 'refund.succeeded',
            'arca:payment.succeeded', 'idram:payment.succeeded' => 'payment.succeeded',
            'arca:refund.succeeded' => 'refund.succeeded',
            default => 'other',
        };
    }

    /**
     * @return array<string, string>
     */
    private function flattenHeaders(Request $request): array
    {
        $out = [];
        foreach ($request->headers->all() as $name => $values) {
            $out[strtolower((string) $name)] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        return $out;
    }
}

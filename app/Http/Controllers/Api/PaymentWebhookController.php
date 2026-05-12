<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Services\Payments\PaymentGatewayService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        if ($normalized === 'payment.succeeded' && $payment->status !== Payment::STATUS_PAID) {
            $paymentService->markPaid($payment);
            PaymentLog::query()->create([
                'payment_id' => $payment->id,
                'event_type' => 'webhook.payment_intent.succeeded',
                'gateway' => $driver,
                'gateway_reference' => $reference,
                'status' => 'succeeded',
                'response_payload' => $payloadArray,
                'ip_address' => $request->ip(),
            ]);

            return;
        }

        if ($normalized === 'payment.failed' && $payment->status === Payment::STATUS_PENDING) {
            $paymentService->markFailed($payment);
            PaymentLog::query()->create([
                'payment_id' => $payment->id,
                'event_type' => 'webhook.payment_intent.failed',
                'gateway' => $driver,
                'gateway_reference' => $reference,
                'status' => 'failed',
                'response_payload' => $payloadArray,
                'ip_address' => $request->ip(),
            ]);

            return;
        }

        if ($normalized === 'refund.succeeded') {
            PaymentLog::query()->create([
                'payment_id' => $payment->id,
                'event_type' => 'webhook.refund.succeeded',
                'gateway' => $driver,
                'gateway_reference' => $reference,
                'status' => 'refunded',
                'response_payload' => $payloadArray,
                'ip_address' => $request->ip(),
            ]);
        }
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

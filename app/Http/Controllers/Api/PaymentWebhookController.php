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
    public function handle(
        Request $request,
        PaymentGatewayService $gatewayService,
        PaymentService $paymentService
    ): JsonResponse {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature', '');

        $constructed = $gatewayService->constructWebhookEvent($payload, $sigHeader);
        if (! $constructed['success']) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = $constructed['event'];

        try {
            switch ($event->type) {
                case 'payment_intent.succeeded':
                    $intentId = $event->data->object->id;
                    $payment = Payment::query()
                        ->where('reference_code', $intentId)
                        ->where('status', '!=', Payment::STATUS_PAID)
                        ->first();
                    if ($payment !== null) {
                        $paymentService->markPaid($payment);
                        PaymentLog::query()->create([
                            'payment_id' => $payment->id,
                            'event_type' => 'webhook.payment_intent.succeeded',
                            'gateway' => 'stripe',
                            'gateway_reference' => $intentId,
                            'status' => $event->data->object->status ?? null,
                            'response_payload' => $event->data->object->toArray(),
                            'ip_address' => $request->ip(),
                        ]);
                    }
                    break;

                case 'payment_intent.payment_failed':
                    $intentId = $event->data->object->id;
                    $payment = Payment::query()
                        ->where('reference_code', $intentId)
                        ->where('status', Payment::STATUS_PENDING)
                        ->first();
                    if ($payment !== null) {
                        $paymentService->markFailed($payment);
                        PaymentLog::query()->create([
                            'payment_id' => $payment->id,
                            'event_type' => 'webhook.payment_intent.failed',
                            'gateway' => 'stripe',
                            'gateway_reference' => $intentId,
                            'status' => $event->data->object->status ?? null,
                            'response_payload' => $event->data->object->toArray(),
                            'ip_address' => $request->ip(),
                        ]);
                    }
                    break;

                default:
                    Log::info('Stripe webhook event received', ['type' => $event->type]);
            }
        } catch (\Throwable $e) {
            Log::error('Stripe webhook handler error', [
                'error' => $e->getMessage(),
                'type' => $event->type ?? null,
            ]);
        }

        return response()->json(['received' => true], 200);
    }
}

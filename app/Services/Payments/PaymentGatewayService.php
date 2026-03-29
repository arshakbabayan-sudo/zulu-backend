<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentLog;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class PaymentGatewayService
{
    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('payment.stripe.secret'));
    }

    /**
     * Create a Stripe PaymentIntent.
     *
     * @return array{success: true, client_secret: string, payment_intent_id: string}|array{success: false, error: string}
     */
    public function createPaymentIntent(Payment $payment, array $metadata = []): array
    {
        $amount = (int) round((float) $payment->amount * 100);
        $currency = strtolower($payment->currency ?? config('payment.stripe.currency', 'usd'));

        try {
            $intent = $this->stripe->paymentIntents->create([
                'amount' => $amount,
                'currency' => $currency,
                'metadata' => array_merge(['payment_id' => $payment->id], $metadata),
            ]);

            PaymentLog::query()->create([
                'payment_id' => $payment->id,
                'event_type' => 'intent.created',
                'gateway' => 'stripe',
                'gateway_reference' => $intent->id,
                'amount' => $payment->amount,
                'currency' => $currency,
                'status' => $intent->status,
                'response_payload' => $intent->toArray(),
            ]);

            $payment->reference_code = $intent->id;
            $payment->save();

            return [
                'success' => true,
                'client_secret' => $intent->client_secret,
                'payment_intent_id' => $intent->id,
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            PaymentLog::query()->create([
                'payment_id' => $payment->id,
                'event_type' => 'intent.failed',
                'gateway' => 'stripe',
                'error_message' => $e->getMessage(),
            ]);
            Log::error('Stripe createPaymentIntent failed', ['error' => $e->getMessage(), 'payment_id' => $payment->id]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Retrieve a PaymentIntent and verify it is succeeded.
     *
     * @return array{success: true, status: string}|array{success: false, error: string}|array{success: false, status: string, error: string}
     */
    public function confirmPaymentIntent(Payment $payment, string $paymentIntentId): array
    {
        try {
            $intent = $this->stripe->paymentIntents->retrieve($paymentIntentId);

            PaymentLog::query()->create([
                'payment_id' => $payment->id,
                'event_type' => 'intent.retrieved',
                'gateway' => 'stripe',
                'gateway_reference' => $intent->id,
                'status' => $intent->status,
                'response_payload' => $intent->toArray(),
            ]);

            if ($intent->status === 'succeeded') {
                return ['success' => true, 'status' => 'succeeded'];
            }

            return ['success' => false, 'status' => $intent->status, 'error' => 'Payment not succeeded'];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe confirmPaymentIntent failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Issue a refund for a PaymentIntent.
     *
     * @return array{success: true, refund_id: string, status: string}|array{success: false, error: string}
     */
    public function refundPaymentIntent(Payment $payment, ?int $amountCents = null): array
    {
        $intentId = $payment->reference_code;
        if (empty($intentId)) {
            return ['success' => false, 'error' => 'No gateway reference found on payment.'];
        }

        try {
            $params = ['payment_intent' => $intentId];
            if ($amountCents !== null) {
                $params['amount'] = $amountCents;
            }
            $refund = $this->stripe->refunds->create($params);

            PaymentLog::query()->create([
                'payment_id' => $payment->id,
                'event_type' => 'refund.created',
                'gateway' => 'stripe',
                'gateway_reference' => $refund->id,
                'amount' => $refund->amount / 100,
                'currency' => $refund->currency,
                'status' => $refund->status,
                'response_payload' => $refund->toArray(),
            ]);

            return ['success' => true, 'refund_id' => $refund->id, 'status' => $refund->status];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe refund failed', ['error' => $e->getMessage(), 'payment_id' => $payment->id]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify Stripe webhook signature and return the event.
     *
     * @return array{success: true, event: \Stripe\Event}|array{success: false, error: string}
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): array
    {
        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                config('payment.stripe.webhook_secret')
            );

            return ['success' => true, 'event' => $event];
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return ['success' => false, 'error' => 'Invalid signature'];
        } catch (\UnexpectedValueException $e) {
            return ['success' => false, 'error' => 'Invalid payload'];
        }
    }
}

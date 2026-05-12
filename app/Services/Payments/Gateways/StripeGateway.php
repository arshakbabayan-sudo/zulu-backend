<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payment;
use App\Models\PaymentLog;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe driver. Carries the implementation previously inline in
 * PaymentGatewayService — behavior is byte-identical, only relocated
 * behind the gateway interface so ArCa/Idram can sit beside it.
 */
class StripeGateway implements PaymentGatewayInterface
{
    private ?StripeClient $stripe = null;

    private ?string $configurationError = null;

    public function __construct()
    {
        $stripeSecret = trim((string) config('payment.stripe.secret', ''));
        if ($stripeSecret === '') {
            $this->configurationError = 'Stripe is selected but STRIPE_SECRET is missing.';

            return;
        }

        $this->stripe = new StripeClient($stripeSecret);
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        return $this->configurationError === null;
    }

    public function configurationError(): ?string
    {
        return $this->configurationError;
    }

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
                'gateway' => 'stripe',
                'client_secret' => $intent->client_secret,
                'gateway_reference' => $intent->id,
            ];
        } catch (ApiErrorException $e) {
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

    public function confirmPaymentIntent(Payment $payment, string $gatewayReference): array
    {
        try {
            $intent = $this->stripe->paymentIntents->retrieve($gatewayReference);

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
        } catch (ApiErrorException $e) {
            Log::error('Stripe confirmPaymentIntent failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

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
        } catch (ApiErrorException $e) {
            Log::error('Stripe refund failed', ['error' => $e->getMessage(), 'payment_id' => $payment->id]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function constructWebhookEvent(string $payload, array $headers): array
    {
        $sigHeader = $headers['stripe-signature'] ?? $headers['Stripe-Signature'] ?? '';
        if ($sigHeader === '') {
            return ['success' => false, 'error' => 'Missing Stripe-Signature header'];
        }

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                (string) config('payment.stripe.webhook_secret')
            );

            $reference = $event->data->object->id ?? '';

            return [
                'success' => true,
                'event_type' => $event->type,
                'gateway_reference' => is_string($reference) ? $reference : '',
                'raw' => $event,
            ];
        } catch (SignatureVerificationException $e) {
            return ['success' => false, 'error' => 'Invalid signature'];
        } catch (\UnexpectedValueException $e) {
            return ['success' => false, 'error' => 'Invalid payload'];
        }
    }
}

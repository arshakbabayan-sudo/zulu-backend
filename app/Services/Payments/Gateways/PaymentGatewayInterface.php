<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payment;

/**
 * Contract every payment driver (Stripe / ArCa / Idram / future) must
 * satisfy so PaymentGatewayService can route to any of them uniformly.
 *
 * All four methods return a discriminated array shape so callers can
 * branch on $result['success'] without needing per-gateway knowledge.
 */
interface PaymentGatewayInterface
{
    /**
     * The driver key as it appears in config('payment.driver').
     */
    public function name(): string;

    /**
     * Whether the driver has the credentials it needs to talk to its API.
     * Routers should refuse to dispatch to a driver that returns false here
     * and surface configuration_error() instead.
     */
    public function isConfigured(): bool;

    /**
     * Human-readable explanation of why isConfigured() is false. Returns
     * null when the driver is ready.
     */
    public function configurationError(): ?string;

    /**
     * Open a new payment session with the upstream gateway and return the
     * client-side token the frontend needs to complete the flow.
     *
     * Stripe returns a PaymentIntent client_secret. ArCa returns a hosted
     * payment URL. Idram returns a form-post target. The router exposes
     * both `client_secret` (for SDK widgets) and `redirect_url` (for
     * hosted flows) so the frontend can pick whichever matches the
     * driver.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{success: true, gateway: string, client_secret?: string, redirect_url?: string, gateway_reference: string}|array{success: false, error: string}
     */
    public function createPaymentIntent(Payment $payment, array $metadata = []): array;

    /**
     * Verify a payment landed in the success state, either by retrieving
     * a PaymentIntent (Stripe) or by querying order status (ArCa/Idram).
     *
     * @return array{success: true, status: string}|array{success: false, status?: string, error: string}
     */
    public function confirmPaymentIntent(Payment $payment, string $gatewayReference): array;

    /**
     * Issue a refund. $amountCents=null means full refund.
     *
     * @return array{success: true, refund_id: string, status: string}|array{success: false, error: string}
     */
    public function refundPaymentIntent(Payment $payment, ?int $amountCents = null): array;

    /**
     * Verify a webhook payload's signature and return the parsed event.
     * Each gateway has its own signing scheme: Stripe uses HMAC-SHA256
     * over `t={ts},v1={sig}` headers; ArCa uses MD5 with a shared secret;
     * Idram uses SHA1 over concatenated fields.
     *
     * Return shape stays array-based (not a typed event) because the
     * upstream event types differ — the router/dispatcher inspects
     * `$result['event_type']` to decide what handler to call.
     *
     * @return array{success: true, event_type: string, gateway_reference: string, raw: mixed}|array{success: false, error: string}
     */
    public function constructWebhookEvent(string $payload, array $headers): array;
}

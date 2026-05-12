<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Payments\Gateways\ArcaGateway;
use App\Services\Payments\Gateways\IdramGateway;
use App\Services\Payments\Gateways\PaymentGatewayInterface;
use App\Services\Payments\Gateways\StripeGateway;
use Illuminate\Support\Facades\Log;

/**
 * Router/facade in front of the per-gateway drivers (Stripe / ArCa /
 * Idram). The public API is unchanged from the original single-driver
 * service so existing callers don't need to be touched.
 *
 * Driver selection is config-based (`PAYMENT_DRIVER` env). For
 * multi-driver setups where the frontend picks per-checkout, callers
 * can override via `usingDriver(string)`.
 */
class PaymentGatewayService
{
    /** @var list<string> */
    public const SUPPORTED_DRIVERS = ['stripe', 'arca', 'idram'];

    private ?PaymentGatewayInterface $gateway = null;

    private ?string $configurationError = null;

    private string $driver;

    public function __construct()
    {
        $configuredDriver = config('payment.driver');
        $this->driver = is_string($configuredDriver) ? strtolower(trim($configuredDriver)) : '';
        $this->resolve($this->driver);
    }

    /**
     * Override the active driver for a single call (e.g. when the
     * frontend tells the backend "this customer chose ArCa"). Returns
     * a clone with the requested driver so the default state survives.
     */
    public function usingDriver(string $driver): self
    {
        $clone = clone $this;
        $clone->driver = strtolower(trim($driver));
        $clone->resolve($clone->driver);

        return $clone;
    }

    public function activeDriver(): string
    {
        return $this->driver;
    }

    public function isConfigured(): bool
    {
        return $this->configurationError === null && $this->gateway !== null && $this->gateway->isConfigured();
    }

    public function configurationError(): ?string
    {
        if ($this->configurationError !== null) {
            return $this->configurationError;
        }

        return $this->gateway?->configurationError();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{success: true, gateway: string, client_secret?: string, redirect_url?: string, gateway_reference: string}|array{success: false, error: string}
     */
    public function createPaymentIntent(Payment $payment, array $metadata = []): array
    {
        $guard = $this->configurationGuard();
        if ($guard !== null) {
            return $guard;
        }

        return $this->gateway->createPaymentIntent($payment, $metadata);
    }

    /**
     * @return array{success: true, status: string}|array{success: false, status?: string, error: string}
     */
    public function confirmPaymentIntent(Payment $payment, string $paymentIntentId): array
    {
        $guard = $this->configurationGuard();
        if ($guard !== null) {
            return $guard;
        }

        return $this->gateway->confirmPaymentIntent($payment, $paymentIntentId);
    }

    /**
     * @return array{success: true, refund_id: string, status: string}|array{success: false, error: string}
     */
    public function refundPaymentIntent(Payment $payment, ?int $amountCents = null): array
    {
        $guard = $this->configurationGuard();
        if ($guard !== null) {
            return $guard;
        }

        return $this->gateway->refundPaymentIntent($payment, $amountCents);
    }

    /**
     * Stripe-shaped wrapper preserved for the existing webhook handler
     * (which passes $sigHeader directly). Internally delegates to the
     * new interface which takes a full header bag.
     *
     * @return array{success: true, event: mixed}|array{success: true, event_type: string, gateway_reference: string, raw: mixed}|array{success: false, error: string}
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): array
    {
        $guard = $this->configurationGuard();
        if ($guard !== null) {
            return $guard;
        }

        $result = $this->gateway->constructWebhookEvent($payload, ['stripe-signature' => $sigHeader]);

        // Backward-compat shape: original Stripe path returned ['event' => Event]
        // for callers that introspect $result['event']->type. The interface
        // returns event_type + raw separately. For Stripe we surface both so
        // legacy code keeps working without modification.
        if (($result['success'] ?? false) === true && $this->driver === 'stripe' && isset($result['raw'])) {
            $result['event'] = $result['raw'];
        }

        return $result;
    }

    /**
     * @return array{success: false, error: string}|null
     */
    private function configurationGuard(): ?array
    {
        if ($this->isConfigured()) {
            return null;
        }

        $error = $this->configurationError() ?? 'Payment gateway is not configured.';
        Log::warning('Payment gateway configuration error', [
            'driver' => $this->driver,
            'error' => $error,
        ]);

        return ['success' => false, 'error' => $error];
    }

    private function resolve(string $driver): void
    {
        $this->configurationError = null;
        $this->gateway = null;

        if ($driver === '') {
            $this->configurationError = 'Payment driver is not configured. Set PAYMENT_DRIVER to one of: '.implode(', ', self::SUPPORTED_DRIVERS).'.';

            return;
        }

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            $this->configurationError = 'Unsupported payment driver "'.$driver.'". Supported: '.implode(', ', self::SUPPORTED_DRIVERS).'.';

            return;
        }

        $this->gateway = match ($driver) {
            'stripe' => new StripeGateway,
            'arca' => new ArcaGateway,
            'idram' => new IdramGateway,
        };
    }
}

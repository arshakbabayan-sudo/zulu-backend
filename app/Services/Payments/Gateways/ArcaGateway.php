<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payment;
use App\Models\PaymentLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ArCa (ՎՏԲ Հայաստան / Ardshinbank / etc.) — Armenian card processor.
 *
 * Hosted-payment-page model: backend posts an order to ArCa, ArCa
 * returns a redirect URL. Customer enters card details on ArCa's page,
 * ArCa redirects back with status + sends an async confirmation
 * webhook. Refunds are server-to-server.
 *
 * STATE OF THIS DRIVER (2026-05-12):
 *   - Endpoint URL + merchant ID + secret key still TBD pending the
 *     merchant agreement with ArCa.
 *   - The HTTP shape below is based on ArCa's public REST documentation
 *     (https://services.ameriabank.am/VPOS/Payments/Pay) which is the
 *     most-deployed flavor; final fields may be renamed once the
 *     contract specifies the gateway endpoint (Ameriabank vs ArCa
 *     Acquiring Center direct).
 *   - All four methods short-circuit on isConfigured() and return a
 *     clear "not configured" error until the env vars land. The shape
 *     of the success path is in place so once credentials arrive, the
 *     only work is plugging the URL/credentials.
 *
 * Activation steps (post-credentials):
 *   1. Set ARCA_ENDPOINT + ARCA_CLIENT_ID + ARCA_USERNAME + ARCA_PASSWORD
 *      + ARCA_WEBHOOK_SECRET in .env
 *   2. Set PAYMENT_DRIVER=arca
 *   3. Verify with a test order against ArCa's test environment
 *   4. Flip to prod ArCa endpoint
 */
class ArcaGateway implements PaymentGatewayInterface
{
    private ?string $configurationError = null;

    private string $endpoint;

    private string $clientId;

    private string $username;

    private string $password;

    private string $webhookSecret;

    public function __construct()
    {
        $this->endpoint = trim((string) config('payment.arca.endpoint', ''));
        $this->clientId = trim((string) config('payment.arca.client_id', ''));
        $this->username = trim((string) config('payment.arca.username', ''));
        $this->password = trim((string) config('payment.arca.password', ''));
        $this->webhookSecret = trim((string) config('payment.arca.webhook_secret', ''));

        $missing = [];
        if ($this->endpoint === '') {
            $missing[] = 'ARCA_ENDPOINT';
        }
        if ($this->clientId === '') {
            $missing[] = 'ARCA_CLIENT_ID';
        }
        if ($this->username === '') {
            $missing[] = 'ARCA_USERNAME';
        }
        if ($this->password === '') {
            $missing[] = 'ARCA_PASSWORD';
        }

        if ($missing !== []) {
            $this->configurationError = 'ArCa driver is missing credentials: '.implode(', ', $missing).'. Provide them once the merchant agreement is finalized, then redeploy.';
        }
    }

    public function name(): string
    {
        return 'arca';
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
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => (string) $this->configurationError];
        }

        $amount = (int) round((float) $payment->amount * 100);
        $currency = $this->numericCurrencyCode((string) $payment->currency);
        $orderId = (string) $payment->id;
        $returnUrl = (string) config('payment.arca.return_url', config('app.url').'/payment/arca/return');

        try {
            $response = Http::asForm()->timeout(15)->post($this->endpoint.'/register.do', [
                'userName' => $this->username,
                'password' => $this->password,
                'orderNumber' => $orderId,
                'amount' => $amount,
                'currency' => $currency,
                'returnUrl' => $returnUrl,
                'clientId' => $this->clientId,
                'jsonParams' => json_encode(array_merge(['payment_id' => $payment->id], $metadata)),
            ]);

            $body = $response->json();
            $orderRef = $body['orderId'] ?? '';
            $formUrl = $body['formUrl'] ?? '';

            if ($response->failed() || $orderRef === '' || $formUrl === '') {
                $err = $body['errorMessage'] ?? 'ArCa register.do failed';
                PaymentLog::query()->create([
                    'payment_id' => $payment->id,
                    'event_type' => 'intent.failed',
                    'gateway' => 'arca',
                    'error_message' => (string) $err,
                ]);

                return ['success' => false, 'error' => (string) $err];
            }

            PaymentLog::query()->create([
                'payment_id' => $payment->id,
                'event_type' => 'intent.created',
                'gateway' => 'arca',
                'gateway_reference' => (string) $orderRef,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => 'pending',
                'response_payload' => $body,
            ]);

            $payment->reference_code = (string) $orderRef;
            $payment->save();

            return [
                'success' => true,
                'gateway' => 'arca',
                'redirect_url' => (string) $formUrl,
                'gateway_reference' => (string) $orderRef,
            ];
        } catch (\Throwable $e) {
            Log::error('ArCa createPaymentIntent failed', ['error' => $e->getMessage(), 'payment_id' => $payment->id]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function confirmPaymentIntent(Payment $payment, string $gatewayReference): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => (string) $this->configurationError];
        }

        try {
            $response = Http::asForm()->timeout(15)->post($this->endpoint.'/getOrderStatusExtended.do', [
                'userName' => $this->username,
                'password' => $this->password,
                'orderId' => $gatewayReference,
            ]);

            $body = $response->json();
            // ArCa OrderStatus: 0=registered, 1=pre-auth, 2=approved (paid), 3=cancelled, 4=refunded, 5=initiated, 6=declined
            $status = (int) ($body['orderStatus'] ?? -1);

            PaymentLog::query()->create([
                'payment_id' => $payment->id,
                'event_type' => 'intent.retrieved',
                'gateway' => 'arca',
                'gateway_reference' => $gatewayReference,
                'status' => (string) $status,
                'response_payload' => $body,
            ]);

            if ($status === 2) {
                return ['success' => true, 'status' => 'succeeded'];
            }

            return ['success' => false, 'status' => (string) $status, 'error' => $body['errorMessage'] ?? 'ArCa payment not approved'];
        } catch (\Throwable $e) {
            Log::error('ArCa confirmPaymentIntent failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function refundPaymentIntent(Payment $payment, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => (string) $this->configurationError];
        }

        $orderId = $payment->reference_code;
        if (empty($orderId)) {
            return ['success' => false, 'error' => 'No gateway reference found on payment.'];
        }

        $amount = $amountCents ?? (int) round((float) $payment->amount * 100);

        try {
            $response = Http::asForm()->timeout(15)->post($this->endpoint.'/refund.do', [
                'userName' => $this->username,
                'password' => $this->password,
                'orderId' => $orderId,
                'amount' => $amount,
            ]);

            $body = $response->json();
            $errCode = (int) ($body['errorCode'] ?? -1);

            if ($errCode !== 0) {
                PaymentLog::query()->create([
                    'payment_id' => $payment->id,
                    'event_type' => 'refund.failed',
                    'gateway' => 'arca',
                    'gateway_reference' => $orderId,
                    'error_message' => $body['errorMessage'] ?? 'ArCa refund failed',
                    'response_payload' => $body,
                ]);

                return ['success' => false, 'error' => $body['errorMessage'] ?? 'ArCa refund failed'];
            }

            PaymentLog::query()->create([
                'payment_id' => $payment->id,
                'event_type' => 'refund.created',
                'gateway' => 'arca',
                'gateway_reference' => $orderId,
                'amount' => $amount / 100,
                'currency' => $payment->currency,
                'status' => 'refunded',
                'response_payload' => $body,
            ]);

            return ['success' => true, 'refund_id' => $orderId.':refund', 'status' => 'refunded'];
        } catch (\Throwable $e) {
            Log::error('ArCa refund failed', ['error' => $e->getMessage(), 'payment_id' => $payment->id]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function constructWebhookEvent(string $payload, array $headers): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => (string) $this->configurationError];
        }

        $data = json_decode($payload, true);
        if (! is_array($data)) {
            return ['success' => false, 'error' => 'Invalid JSON payload'];
        }

        $orderId = (string) ($data['orderId'] ?? '');
        $checksum = (string) ($data['checksum'] ?? '');
        $status = (string) ($data['orderStatus'] ?? '');

        // ArCa default signing: MD5 of orderId + amount + secret. The exact
        // recipe varies per merchant agreement — replace with the formula
        // from the merchant onboarding doc once available.
        $expected = strtoupper(md5($orderId.($data['amount'] ?? '').$this->webhookSecret));
        if (! hash_equals($expected, strtoupper($checksum))) {
            return ['success' => false, 'error' => 'Invalid checksum'];
        }

        $eventType = $status === '2' ? 'payment.succeeded' : ($status === '4' ? 'refund.succeeded' : 'payment.updated');

        return [
            'success' => true,
            'event_type' => $eventType,
            'gateway_reference' => $orderId,
            'raw' => $data,
        ];
    }

    private function numericCurrencyCode(string $iso): int
    {
        return match (strtoupper($iso)) {
            'AMD' => 51,
            'USD' => 840,
            'EUR' => 978,
            'RUB' => 643,
            default => 840,
        };
    }
}

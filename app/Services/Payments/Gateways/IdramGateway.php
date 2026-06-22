<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payment;
use App\Models\PaymentLog;
use Illuminate\Support\Facades\Log;

/**
 * Idram — Armenian electronic wallet + card aggregator.
 *
 * Hosted-form model: backend prepares a form (EDP_REC_ACCOUNT,
 * EDP_AMOUNT, EDP_BILL_NO, EDP_DESCRIPTION) and posts it from the
 * frontend to Idram's payment URL. Idram redirects back with status
 * and sends a server-to-server confirmation webhook. Refunds happen
 * via Idram's merchant cabinet (manual) or via partner-tier API
 * (auto, requires additional contract).
 *
 * STATE OF THIS DRIVER (2026-05-12):
 *   - EDP_REC_ACCOUNT (Idram merchant ID) + SECRET still TBD pending
 *     the business account approval with Idram.
 *   - Webhook payload format matches Idram's "Result page POST"
 *     specification: EDP_PAYER_ACCOUNT + EDP_PREDEFINED_FIELDS_RESPONSE
 *     + EDP_AMOUNT + EDP_BILL_NO + EDP_TRANS_ID + EDP_TRANS_DATE +
 *     EDP_CHECKSUM. Verified via SHA1(EDP_REC_ACCOUNT + secret + ...).
 *   - Refund returns a "manual" sentinel because auto-refund needs the
 *     partner-tier API contract upgrade. Customer-service flow falls
 *     back to manual processing through Idram's cabinet until then.
 *
 * Activation steps (post-credentials):
 *   1. Set IDRAM_RECEIVER + IDRAM_SECRET + IDRAM_RETURN_URL in .env
 *   2. Set PAYMENT_DRIVER=idram
 *   3. Test against Idram's sandbox URL (https://money.idram.am/payment.aspx?lang=en)
 *   4. Flip to prod after sandbox confirms
 */
class IdramGateway implements PaymentGatewayInterface
{
    private ?string $configurationError = null;

    private string $receiver;

    private string $secret;

    private string $returnUrl;

    private string $paymentUrl;

    public function __construct()
    {
        $this->receiver = trim((string) config('payment.idram.receiver', ''));
        $this->secret = trim((string) config('payment.idram.secret', ''));
        $this->returnUrl = trim((string) config('payment.idram.return_url', config('app.url').'/payment/idram/return'));
        $this->paymentUrl = trim((string) config('payment.idram.payment_url', 'https://money.idram.am/payment.aspx'));

        $missing = [];
        if ($this->receiver === '') {
            $missing[] = 'IDRAM_RECEIVER';
        }
        if ($this->secret === '') {
            $missing[] = 'IDRAM_SECRET';
        }

        if ($missing !== []) {
            $this->configurationError = 'Idram driver is missing credentials: '.implode(', ', $missing).'. Provide them once the business account is approved, then redeploy.';
        }
    }

    public function name(): string
    {
        return 'idram';
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

        // Idram is hosted-form, not API-call: the "intent" is just the
        // params the frontend must POST to $paymentUrl.
        $billNo = (string) $payment->id;
        $amount = number_format((float) $payment->amount, 2, '.', '');
        $description = (string) ($metadata['description'] ?? ('Order '.$billNo));

        PaymentLog::query()->create([
            'payment_id' => $payment->id,
            'event_type' => 'intent.created',
            'gateway' => 'idram',
            'gateway_reference' => $billNo,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => 'pending',
            'response_payload' => [
                'payment_url' => $this->paymentUrl,
                'fields' => [
                    'EDP_LANGUAGE' => 'EN',
                    'EDP_REC_ACCOUNT' => $this->receiver,
                    'EDP_DESCRIPTION' => $description,
                    'EDP_AMOUNT' => $amount,
                    'EDP_BILL_NO' => $billNo,
                ],
            ],
        ]);

        $payment->reference_code = $billNo;
        $payment->save();

        // The frontend renders an auto-submitting <form> against
        // $paymentUrl with these fields. We return both the URL and the
        // payload so the Next.js side can do that without round-tripping.
        return [
            'success' => true,
            'gateway' => 'idram',
            'redirect_url' => $this->paymentUrl,
            'gateway_reference' => $billNo,
            // Extra field; the interface allows it via array contract.
            'form_fields' => [
                'EDP_LANGUAGE' => 'EN',
                'EDP_REC_ACCOUNT' => $this->receiver,
                'EDP_DESCRIPTION' => $description,
                'EDP_AMOUNT' => $amount,
                'EDP_BILL_NO' => $billNo,
            ],
        ];
    }

    public function confirmPaymentIntent(Payment $payment, string $gatewayReference): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => (string) $this->configurationError];
        }

        // Idram has no order-status query endpoint on the basic tier;
        // confirmation arrives via the webhook (constructWebhookEvent).
        // If a caller invokes this method, they are asking "is the
        // PaymentLog showing a confirmed webhook for this payment?"
        $latest = PaymentLog::query()
            ->where('payment_id', $payment->id)
            ->where('gateway', 'idram')
            ->where('event_type', 'webhook.confirmed')
            ->latest('id')
            ->first();

        if ($latest !== null) {
            return ['success' => true, 'status' => 'succeeded'];
        }

        return ['success' => false, 'status' => 'pending', 'error' => 'No Idram webhook confirmation received yet.'];
    }

    public function refundPaymentIntent(Payment $payment, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => (string) $this->configurationError];
        }

        // Idram basic tier has no programmatic refund — these are done
        // manually through the merchant cabinet. Log the request so the
        // ops team can chase it.
        PaymentLog::query()->create([
            'payment_id' => $payment->id,
            'event_type' => 'refund.manual_required',
            'gateway' => 'idram',
            'gateway_reference' => $payment->reference_code,
            'amount' => $amountCents !== null ? ($amountCents / 100) : $payment->amount,
            'currency' => $payment->currency,
            'status' => 'pending_manual',
            'response_payload' => [
                'message' => 'Idram refund must be processed manually via money.idram.am merchant cabinet. Auto-refund needs partner-tier API contract.',
            ],
        ]);

        Log::warning('Idram refund requires manual processing', ['payment_id' => $payment->id]);

        return ['success' => false, 'error' => 'Idram refunds must be processed manually via the merchant cabinet (partner-tier API contract pending).'];
    }

    public function constructWebhookEvent(string $payload, array $headers): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => (string) $this->configurationError];
        }

        // Idram POSTs as application/x-www-form-urlencoded; the raw body
        // comes through as a query string. Parse it back into an array.
        parse_str($payload, $data);
        if (! is_array($data) || $data === []) {
            return ['success' => false, 'error' => 'Empty or invalid form payload'];
        }

        $billNo = (string) ($data['EDP_BILL_NO'] ?? '');
        $amount = (string) ($data['EDP_AMOUNT'] ?? '');
        $payer = (string) ($data['EDP_PAYER_ACCOUNT'] ?? '');
        $checksum = strtoupper((string) ($data['EDP_CHECKSUM'] ?? ''));

        // Idram checksum: SHA1(EDP_REC_ACCOUNT:secret:EDP_AMOUNT:EDP_BILL_NO:EDP_PAYER_ACCOUNT)
        // (Exact recipe in Idram's merchant integration PDF; tweak when contract specifies.)
        $expected = strtoupper(sha1($this->receiver.':'.$this->secret.':'.$amount.':'.$billNo.':'.$payer));
        if (! hash_equals($expected, $checksum)) {
            return ['success' => false, 'error' => 'Invalid checksum'];
        }

        // Audit C3 — EDP_TRANS_ID is Idram's unique per-transaction id, so it is
        // the natural idempotency key for dedupe. Surface it so the webhook
        // controller can persist it on payment_logs.gateway_event_id; the
        // controller falls back to a composite token when it is absent.
        $transId = (string) ($data['EDP_TRANS_ID'] ?? '');

        return [
            'success' => true,
            'event_type' => 'payment.succeeded',
            'gateway_reference' => $billNo,
            'event_id' => $transId,
            'raw' => $data,
        ];
    }
}

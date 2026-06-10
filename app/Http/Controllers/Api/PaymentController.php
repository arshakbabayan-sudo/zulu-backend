<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentRefundFailedException;
use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Concerns\AuthorizesCommerceAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Admin\AdminAccessService;
use App\Services\Payments\PaymentGatewayService;
use App\Services\Payments\PaymentService;
use App\Services\Pdf\PaymentReceiptPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentController extends Controller
{
    use AuthorizesCommerceAccess;
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService,
        private PaymentGatewayService $paymentGatewayService,
    ) {}

    public function index(Request $request, PaymentService $paymentService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'payments.view');

        if (! $request->filled('page')) {
            $payments = $paymentService->listForCompanies($companyIds);

            return response()->json([
                'success' => true,
                'data' => PaymentResource::collection($payments)->resolve(),
            ]);
        }

        $paginator = $paymentService->paginateForCompanies($companyIds, $this->commerceListPerPage($request));

        return $this->paginatedCommerceResourceResponse($request, $paginator, PaymentResource::class);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $payment->loadMissing('invoice.order');
        $companyId = $payment->invoice?->order?->company_id;
        if ($companyId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $companyId, 'payments.view')) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => PaymentResource::make($payment)->toArray($request),
        ]);
    }

    public function store(Request $request, PaymentService $paymentService): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
        ]);

        $invoice = Invoice::query()->with('order')->findOrFail((int) $validated['invoice_id']);
        $companyId = $invoice->order?->company_id;
        if ($companyId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $companyId, 'payments.create')) {
            return $response;
        }

        $payment = $paymentService->createForInvoice($invoice, []);

        $gatewayResult = $this->paymentGatewayService->createPaymentIntent($payment);
        if (! $gatewayResult['success']) {
            return response()->json([
                'success' => false,
                'message' => $gatewayResult['error'] ?? 'Payment intent creation failed',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => PaymentResource::make($payment->fresh())->toArray($request),
            'client_secret' => $gatewayResult['client_secret'],
            'payment_intent_id' => $gatewayResult['payment_intent_id'],
        ]);
    }

    public function pay(Request $request, PaymentService $paymentService, Payment $payment): JsonResponse
    {
        $payment->loadMissing('invoice.order');
        $companyId = $payment->invoice?->order?->company_id;
        if ($companyId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $companyId, 'payments.pay')) {
            return $response;
        }

        $validated = $request->validate([
            'payment_intent_id' => ['required', 'string'],
        ]);

        $confirm = $this->paymentGatewayService->confirmPaymentIntent($payment, $validated['payment_intent_id']);
        if (! $confirm['success']) {
            return response()->json([
                'success' => false,
                'message' => $confirm['error'] ?? 'Payment confirmation failed',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => PaymentResource::make($paymentService->markPaid($payment))->toArray($request),
        ]);
    }

    public function capture(Request $request, PaymentService $paymentService, Payment $payment): JsonResponse
    {
        $payment->loadMissing('invoice.order');
        $companyId = $payment->invoice?->order?->company_id;
        if ($companyId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $companyId, 'payments.capture')) {
            return $response;
        }

        $validated = $request->validate([
            'payment_intent_id' => ['required', 'string'],
        ]);

        $confirm = $this->paymentGatewayService->confirmPaymentIntent($payment, $validated['payment_intent_id']);
        if (! $confirm['success']) {
            return response()->json([
                'success' => false,
                'message' => $confirm['error'] ?? 'Payment confirmation failed',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => PaymentResource::make($paymentService->markPaid($payment))->toArray($request),
        ]);
    }

    /**
     * POST /payments/{payment}/retry — roadmap 10.06 §5.
     *
     * Re-initiates the gateway charge for a FAILED payment: a fresh payment
     * intent is created (the old reference_code stays in PaymentLog as audit
     * trail) and the new client_secret is returned so the front can reopen
     * the payment flow. Cash/bank-transfer payments are not retryable — the
     * money is collected outside any gateway.
     */
    public function retry(Request $request, Payment $payment): JsonResponse
    {
        $payment->loadMissing('invoice.order');
        $companyId = $payment->invoice?->order?->company_id;
        if ($companyId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $companyId, 'payments.capture')) {
            return $response;
        }

        if ($payment->status !== Payment::STATUS_FAILED) {
            return response()->json([
                'success' => false,
                'message' => "Only failed payments can be retried (this one is {$payment->status}).",
            ], 422);
        }

        if (! in_array($payment->payment_method, ['card', 'stripe', 'arca', 'idram'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This payment method does not support automatic retry — collect it manually.',
            ], 422);
        }

        $result = $this->paymentGatewayService->createPaymentIntent($payment);
        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Retry failed at the payment gateway',
            ], 422);
        }

        // Back to pending — the customer can now complete the new intent.
        $payment->status = Payment::STATUS_PENDING;
        $payment->save();

        return response()->json([
            'success' => true,
            'data' => PaymentResource::make($payment->fresh())->toArray($request),
            'client_secret' => $result['client_secret'] ?? null,
            'gateway_reference' => $result['gateway_reference'] ?? null,
        ]);
    }

    public function fail(Request $request, PaymentService $paymentService, Payment $payment): JsonResponse
    {
        $payment->loadMissing('invoice.order');
        $companyId = $payment->invoice?->order?->company_id;
        if ($companyId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $companyId, 'payments.fail')) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => PaymentResource::make($paymentService->markFailed($payment))->toArray($request),
        ]);
    }

    /**
     * Finance group v2 — payment receipt PDF download.
     *
     * Returns a generated PDF (`receipt-{id}-{ref}.pdf`) for the given payment.
     * Visible to: super admin, the operator company on the linked invoice's
     * order, or the customer who owns the order. All others get 403.
     */
    public function receiptPdf(
        Request $request,
        Payment $payment,
        PaymentReceiptPdfService $pdfService
    ): Response {
        $payment->loadMissing('invoice.order');
        $user = $request->user();
        $orderCompanyId = $payment->invoice?->order?->company_id;
        $orderUserId = $payment->invoice?->order?->user_id;

        $allowed = $user !== null && (
            ($user->is_super_admin ?? false)
            || ($orderCompanyId !== null && $this->ensureCommerceAccess($request, (int) $orderCompanyId, 'payments.view') === null)
            || ($orderUserId !== null && (int) $user->id === (int) $orderUserId)
        );
        if (! $allowed) {
            abort(403, 'Forbidden');
        }

        return $pdfService->generate($payment);
    }

    public function refund(Request $request, PaymentService $paymentService, Payment $payment): JsonResponse
    {
        $payment->loadMissing('invoice.order');
        $companyId = $payment->invoice?->order?->company_id;
        if ($companyId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $companyId, 'payments.refund')) {
            return $response;
        }

        try {
            $refreshed = $paymentService->refund($payment);
        } catch (PaymentRefundFailedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->reason,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => PaymentResource::make($refreshed)->toArray($request),
        ]);
    }
}

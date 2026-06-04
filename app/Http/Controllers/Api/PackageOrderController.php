<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Concerns\AuthorizesCommerceAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\OrderResource;
use App\Models\CustomerPartner;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Package;
use App\Models\Payment;
use App\Services\Admin\AdminAccessService;
use App\Services\Packages\PackageOrderService;
use App\Services\Payments\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PackageOrderController extends Controller
{
    use AuthorizesCommerceAccess;
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function store(Request $request, PackageOrderService $service): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'adults_count' => ['sometimes', 'integer', 'min:1'],
            'children_count' => ['sometimes', 'integer', 'min:0'],
            'infants_count' => ['sometimes', 'integer', 'min:0'],
            'booking_channel' => ['sometimes', 'string', Rule::in(Order::BOOKING_CHANNELS)],
            'notes' => ['nullable', 'string'],
            // Seller-attribution: the company the customer chose to buy through
            // (a partner-agent, or the operator itself for a direct purchase).
            'seller_company_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $package = Package::query()->findOrFail((int) $validated['package_id']);

        $agentCompanyId = CustomerPartner::resolveChosenAgent(
            (int) $request->user()->id,
            isset($validated['seller_company_id']) ? (int) $validated['seller_company_id'] : null,
            $package->company_id !== null ? (int) $package->company_id : null,
        );

        $input = [
            'adults_count' => $validated['adults_count'] ?? 1,
            'children_count' => $validated['children_count'] ?? 0,
            'infants_count' => $validated['infants_count'] ?? 0,
            'booking_channel' => $validated['booking_channel'] ?? 'public_b2c',
            'notes' => $validated['notes'] ?? null,
            'agent_company_id' => $agentCompanyId,
        ];

        $order = $service->createOrder($package, $request->user(), $input);

        return response()->json([
            'success' => true,
            'data' => OrderResource::make($order)->toArray($request),
        ], 201);
    }

    public function index(Request $request, PackageOrderService $service): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));
        $paginator = $service->listForUser($request->user(), $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, OrderResource::class);
    }

    public function show(Request $request, string $order, PackageOrderService $service): JsonResponse
    {
        $model = $service->findForUserUuid($order, $request->user());
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => OrderResource::make($model)->toArray($request),
        ]);
    }

    public function markPaid(Request $request, string $order, PackageOrderService $service): JsonResponse
    {
        $model = $service->findForUserUuid($order, $request->user());
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        $updated = $service->markPaid($model);

        return response()->json([
            'success' => true,
            'data' => OrderResource::make($updated)->toArray($request),
        ]);
    }

    /**
     * P0-1 step 1.3 — customer-side Stripe PaymentIntent for a package order.
     *
     * Idempotent: re-calling for the same order reuses the latest Invoice +
     * pending Payment + Stripe PaymentIntent (Stripe's own intent.create
     * dedupes nothing, so we cache reference_code on the Payment row to
     * avoid creating duplicate intents on retry). The actual marketplace
     * split (application_fee + transfer_data[destination]) is wired in
     * StripeGateway::createPaymentIntent (step 1.2).
     *
     * Auth: only the user who placed the order can call this — same
     * findForUserUuid() filter as markPaid.
     */
    public function createPaymentIntent(
        Request $request,
        string $order,
        PackageOrderService $service,
        PaymentGatewayService $gateway
    ): JsonResponse {
        $model = $service->findForUserUuid($order, $request->user());
        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        if ($model->status === 'paid' || $model->status === 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Order is already paid.',
            ], 409);
        }

        $currency = strtoupper((string) ($model->currency ?? 'USD'));
        $total = (float) ($model->total ?? 0);
        if ($total <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Order total must be greater than zero.',
            ], 422);
        }

        if (! $gateway->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Payment gateway is not configured on this server.',
                'detail' => $gateway->configurationError(),
            ], 503);
        }

        [$invoice, $payment] = DB::transaction(function () use ($model, $total, $currency): array {
            $invoice = Invoice::query()
                ->where('order_id', $model->id)
                ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_ISSUED])
                ->latest('id')
                ->first();
            if ($invoice === null) {
                $invoice = Invoice::query()->create([
                    'order_id' => $model->id,
                    'total_amount' => $total,
                    'currency' => $currency,
                    'status' => Invoice::STATUS_PENDING,
                ]);
            }

            $payment = Payment::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', Payment::STATUS_PENDING)
                ->latest('id')
                ->first();
            if ($payment === null) {
                $payment = Payment::query()->create([
                    'invoice_id' => $invoice->id,
                    'amount' => $total,
                    'currency' => $currency,
                    'status' => Payment::STATUS_PENDING,
                ]);
            }

            return [$invoice, $payment];
        });

        $result = $gateway->createPaymentIntent($payment);
        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Failed to create Stripe PaymentIntent.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'client_secret' => $result['client_secret'],
                'payment_intent_id' => $result['gateway_reference'] ?? null,
                'publishable_key' => (string) config('payment.stripe.key', ''),
                'currency' => strtolower($currency),
                'amount' => $total,
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
            ],
        ]);
    }

    public function companyIndex(Request $request, PackageOrderService $service): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'package_orders.view');
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));
        $paginator = $service->listForCompanies($companyIds, $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, OrderResource::class);
    }

    public function companyShow(Request $request, string $order, PackageOrderService $service): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'package_orders.view');
        $model = $service->findForCompanyScopeUuid($order, $companyIds);
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => OrderResource::make($model)->toArray($request),
        ]);
    }

    public function confirmItem(Request $request, string $order, string $item, PackageOrderService $service): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'package_orders.manage');
        $model = $service->findForCompanyScopeUuid($order, $companyIds);
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        $service->confirmItem($model, $item);
        $updated = $service->findForCompanyScopeUuid($order, $companyIds);

        return response()->json([
            'success' => true,
            'data' => OrderResource::make($updated)->toArray($request),
        ]);
    }

    public function failItem(Request $request, string $order, string $item, PackageOrderService $service): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'package_orders.manage');
        $model = $service->findForCompanyScopeUuid($order, $companyIds);
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        $service->failItem($model, $item, $validated['reason']);
        $updated = $service->findForCompanyScopeUuid($order, $companyIds);

        return response()->json([
            'success' => true,
            'data' => OrderResource::make($updated)->toArray($request),
        ]);
    }

    public function cancelOrder(Request $request, string $order, PackageOrderService $service): JsonResponse
    {
        $model = $service->findForUserUuid($order, $request->user());
        if ($model === null) {
            $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'package_orders.manage');
            $model = $service->findForCompanyScopeUuid($order, $companyIds);
        }
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        $updated = $service->cancelOrder($model);

        return response()->json([
            'success' => true,
            'data' => OrderResource::make($updated)->toArray($request),
        ]);
    }
}

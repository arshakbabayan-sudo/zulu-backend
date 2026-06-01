<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\InvoiceResource;
use App\Http\Resources\Api\PaymentResource;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Marketplace\MarketplaceService;
use App\Services\Payments\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceController extends Controller
{
    public function store(Request $request, MarketplaceService $marketplaceService): JsonResponse
    {
        $validated = $request->validate([
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
        ]);

        $offer = Offer::query()->findOrFail((int) $validated['offer_id']);
        if ($offer->status !== Offer::STATUS_PUBLISHED) {
            return response()->json([
                'success' => false,
                'message' => 'Offer not available',
            ], 422);
        }

        $order = $marketplaceService->createBooking($request->user(), $offer);

        return response()->json([
            'success' => true,
            'data' => [
                'order' => $order->load('items'),
            ],
        ]);
    }

    public function show(Request $request, string $orderId): JsonResponse
    {
        $order = Order::query()->findOrFail($orderId);
        if ((int) $order->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $order->load(['items', 'invoices.payments']);
        $invoice = $order->invoices->first();
        $payment = $invoice?->payments->first();

        return response()->json([
            'success' => true,
            'data' => [
                'order' => $order,
                'invoice' => $invoice ? InvoiceResource::make($invoice)->toArray($request) : null,
                'payment' => $payment ? PaymentResource::make($payment)->toArray($request) : null,
            ],
        ]);
    }

    public function checkout(Request $request, string $orderId, MarketplaceService $marketplaceService): JsonResponse
    {
        $order = Order::query()->findOrFail($orderId);
        if ((int) $order->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $result = $marketplaceService->checkoutPaidBooking($order);
        $result['order']->load(['items']);

        return response()->json([
            'success' => true,
            'data' => [
                'order' => $result['order'],
                'invoice' => InvoiceResource::make($result['invoice'])->toArray($request),
                'payment' => PaymentResource::make($result['payment'])->toArray($request),
            ],
        ]);
    }

    /**
     * P0-1 step 1.5 (extension) — UNIFIED Stripe PaymentIntent for ANY
     * marketplace order (hotel / flight / car / transfer / excursion /
     * package / user-assembled mix). Same shape as the package-specific
     * endpoint but with no legacy_origin filter — works for every booking
     * created via POST /api/marketplace/bookings.
     *
     * Idempotent: reuses the latest pending Invoice + Payment on retry.
     * The marketplace split (application_fee + transfer_data[destination])
     * is applied inside StripeGateway::createPaymentIntent (step 1.2) —
     * no extra wiring needed here.
     */
    public function paymentIntent(
        Request $request,
        string $orderId,
        PaymentGatewayService $gateway
    ): JsonResponse {
        $order = Order::query()->findOrFail($orderId);
        if ((int) $order->user_id !== (int) $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }
        if ($order->status === 'paid' || $order->status === 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Order is already paid.',
            ], 409);
        }

        $currency = strtoupper((string) ($order->currency ?? 'USD'));
        $total = (float) ($order->total ?? 0);
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

        [$invoice, $payment] = DB::transaction(function () use ($order, $total, $currency): array {
            $invoice = Invoice::query()
                ->where('order_id', $order->id)
                ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_ISSUED])
                ->latest('id')
                ->first();
            if ($invoice === null) {
                $invoice = Invoice::query()->create([
                    'order_id' => $order->id,
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
                'order_id' => $order->id,
            ],
        ]);
    }
}

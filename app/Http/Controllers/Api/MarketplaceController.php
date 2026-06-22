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
use App\Services\Payments\ChargeAmountResolver;
use App\Services\Payments\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceController extends Controller
{
    public function store(Request $request, MarketplaceService $marketplaceService): JsonResponse
    {
        // C4 — accept the on-screen multiplier (`quantity`) and the booking
        // selection (`meta`) so the server reproduces the displayed total
        // (unit_price × quantity) via the existing pricing pipeline instead of
        // charging the bare offer price. SAFE-BY-DEFAULT: both are optional —
        // an { offer_id }-only request behaves EXACTLY as before (quantity 1,
        // no meta). `meta` is validated loosely (a bounded free-form object)
        // and read via $request->input() so untyped sub-keys survive.
        $validated = $request->validate([
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'meta' => ['sometimes', 'nullable', 'array'],
            'meta.pax.adults' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'meta.pax.children' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'meta.pax.infants' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'meta.contact.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta.contact.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'meta.contact.phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'meta.cabin' => ['sometimes', 'nullable', 'string', 'max:64'],
            'meta.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $offer = Offer::query()->findOrFail((int) $validated['offer_id']);
        if ($offer->status !== Offer::STATUS_PUBLISHED) {
            return response()->json([
                'success' => false,
                'message' => 'Offer not available',
            ], 422);
        }

        $quantity = (int) ($validated['quantity'] ?? 1);
        // Read the raw object (not the validated subset) so deep, untyped
        // selection keys are preserved on the record.
        $meta = $request->input('meta');
        if (! is_array($meta)) {
            $meta = null;
        }

        // Bound the free-form meta so an authenticated client can't write an
        // unbounded blob into orders.metadata. The known sub-keys are already
        // length-limited above; this caps unknown keys too (8KB is far above
        // any legitimate pax/dates/contact/notes selection).
        if ($meta !== null && strlen((string) json_encode($meta)) > 8192) {
            return response()->json([
                'success' => false,
                'message' => 'Booking selection (meta) is too large.',
            ], 422);
        }

        $order = $marketplaceService->createBooking($request->user(), $offer, $quantity, $meta);

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
        PaymentGatewayService $gateway,
        ChargeAmountResolver $chargeResolver
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

        // B3 — server-authoritative customer charge. When a non-AMD order is
        // paid in the Armenia phase, the gateway must charge the AMD amount
        // (order.total × seller-effective-rate), with the rate FROZEN at
        // intent-create time. SAFE-BY-DEFAULT: if the order is already AMD, or
        // no seller FX setting / base rate resolves, this returns the order's
        // native amount/currency unchanged and writes no snapshot — zero
        // behaviour change for unconfigured platforms. The amount/currency are
        // ALWAYS derived server-side from the Order, never from the request.
        [$invoice, $payment] = DB::transaction(function () use ($order, $total, $currency, $chargeResolver): array {
            $charge = $chargeResolver->resolveForOrder($order, $total, $currency);
            $chargeAmount = $charge['amount'];
            $chargeCurrency = $charge['currency'];

            $invoice = Invoice::query()
                ->where('order_id', $order->id)
                ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_ISSUED])
                ->latest('id')
                ->first();
            if ($invoice === null) {
                $invoice = Invoice::query()->create([
                    'order_id' => $order->id,
                    'total_amount' => $chargeAmount,
                    'currency' => $chargeCurrency,
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
                    'amount' => $chargeAmount,
                    'currency' => $chargeCurrency,
                    'status' => Payment::STATUS_PENDING,
                ]);
            } elseif (
                (float) $payment->amount !== $chargeAmount
                || strtoupper((string) $payment->currency) !== $chargeCurrency
            ) {
                // Idempotent reuse: a pending Payment from a prior call may still
                // carry the native (pre-conversion) amount/currency. Align it to
                // the resolved charge BEFORE the gateway reads it. Note the
                // resolver returns the ALREADY-FROZEN charge on reuse (it reads
                // the existing snapshot), so this never re-prices a placed order.
                $payment->amount = $chargeAmount;
                $payment->currency = $chargeCurrency;
                $payment->save();
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

        // Surface the ACTUAL charged amount/currency (server-authoritative,
        // post-conversion) so the client renders what is really being charged.
        return response()->json([
            'success' => true,
            'data' => [
                'client_secret' => $result['client_secret'],
                'payment_intent_id' => $result['gateway_reference'] ?? null,
                'publishable_key' => (string) config('payment.stripe.key', ''),
                'currency' => strtolower((string) $payment->currency),
                'amount' => (float) $payment->amount,
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'order_id' => $order->id,
            ],
        ]);
    }
}

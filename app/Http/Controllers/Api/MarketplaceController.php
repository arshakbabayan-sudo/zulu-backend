<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\InvoiceResource;
use App\Http\Resources\Api\PaymentResource;
use App\Models\Offer;
use App\Models\Order;
use App\Services\Marketplace\MarketplaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}

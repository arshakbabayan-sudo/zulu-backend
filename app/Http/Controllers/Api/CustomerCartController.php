<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Services\Cart\CartService;
use App\Services\Cart\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

class CustomerCartController extends Controller
{
    public function __construct(
        private CartService $cart,
        private CheckoutService $checkout,
    ) {}

    /** GET /api/customer/cart */
    public function show(Request $request): JsonResponse
    {
        $cart = $this->cart->findOpenCart($request->user());

        return response()->json([
            'success' => true,
            'data' => $cart,
        ]);
    }

    /** POST /api/customer/cart/items */
    public function addItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_type' => ['required', 'string', Rule::in(OrderItem::ITEM_TYPES)],
            'item_id' => ['nullable', 'integer'],
            'package_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'service_snapshot' => ['nullable', 'array'],
            'passenger_data' => ['nullable', 'array'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'external_ref' => ['nullable', 'string'],
        ]);

        try {
            $cart = $this->cart->addItem($request->user(), $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $cart], 201);
    }

    /** PATCH /api/customer/cart/items/{itemId} */
    public function updateItem(Request $request, string $itemId): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $cart = $this->cart->updateItemQuantity($request->user(), $itemId, (int) $validated['quantity']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }

        return response()->json(['success' => true, 'data' => $cart]);
    }

    /** DELETE /api/customer/cart/items/{itemId} */
    public function removeItem(Request $request, string $itemId): JsonResponse
    {
        try {
            $cart = $this->cart->removeItem($request->user(), $itemId);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }

        return response()->json(['success' => true, 'data' => $cart]);
    }

    /** DELETE /api/customer/cart */
    public function clear(Request $request): JsonResponse
    {
        try {
            $cart = $this->cart->clearCart($request->user());
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }

        return response()->json(['success' => true, 'data' => $cart]);
    }

    /** POST /api/customer/cart/checkout */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
            'passenger_data' => ['nullable', 'array'],
            'booking_channel' => ['nullable', 'string'],
            'hold_minutes' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        try {
            $order = $this->checkout->checkout($request->user(), $validated);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $order], 201);
    }
}

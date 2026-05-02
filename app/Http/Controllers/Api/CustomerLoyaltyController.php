<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CustomerLoyaltyController extends Controller
{
    public function __construct(
        private LoyaltyService $service,
    ) {}

    /** GET /api/customer/loyalty */
    public function show(Request $request): JsonResponse
    {
        $account = $this->service->getOrCreateAccount($request->user());
        $account->loadMissing('transactions');

        return response()->json(['success' => true, 'data' => $account]);
    }

    /** POST /api/customer/loyalty/redeem */
    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'string'],
            'points' => ['required', 'integer', 'min:1'],
        ]);

        $order = Order::query()
            ->where('id', $validated['order_id'])
            ->where('user_id', $request->user()->id)
            ->first();

        if ($order === null) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        try {
            $result = $this->service->redeemForOrder($request->user(), $order, (int) $validated['points']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $result], 201);
    }
}

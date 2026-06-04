<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RefundRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Customer-facing refund requests (account section #12). The customer asks for
 * a refund on their own paid/confirmed order; a platform admin reviews it later
 * (admin queue + gateway refund = roadmap P1-2 follow-on).
 */
class RefundRequestController extends Controller
{
    /** Orders a customer may request a refund on. */
    private const REFUNDABLE_STATUSES = ['paid', 'confirmed'];

    public function index(Request $request): JsonResponse
    {
        $rows = RefundRequest::query()
            ->where('user_id', $request->user()->id)
            ->with('order:id,order_number,status,total,currency')
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:2000'],
            'amount_requested' => ['nullable', 'numeric', 'min:0'],
        ]);

        $userId = $request->user()->id;

        $order = Order::query()
            ->where('id', $validated['order_id'])
            ->where('user_id', $userId)
            ->first();

        if ($order === null) {
            throw ValidationException::withMessages(['order_id' => ['Order not found.']]);
        }

        if (! in_array($order->status, self::REFUNDABLE_STATUSES, true)) {
            throw ValidationException::withMessages(['order_id' => ['This order is not eligible for a refund request.']]);
        }

        if (RefundRequest::query()->where('order_id', $order->id)->where('status', RefundRequest::STATUS_PENDING)->exists()) {
            throw ValidationException::withMessages(['order_id' => ['A refund request for this order is already pending.']]);
        }

        $refund = RefundRequest::query()->create([
            'user_id' => $userId,
            'order_id' => $order->id,
            'reason' => $validated['reason'],
            'amount_requested' => $validated['amount_requested'] ?? null,
            'status' => RefundRequest::STATUS_PENDING,
        ]);

        return response()->json(['success' => true, 'data' => $refund], 201);
    }
}

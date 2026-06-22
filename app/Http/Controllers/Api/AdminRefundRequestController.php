<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Services\Notifications\NotificationService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * §8 — admin refund-request queue. The customer submits a refund request from
 * their account (RefundRequestController); a platform admin reviews it here and,
 * on approval, issues the real gateway refund. Gated platform-admin by the
 * `platform-admin` route-group middleware.
 */
class AdminRefundRequestController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly NotificationService $notifications,
    ) {}

    /** GET /platform-admin/refund-requests?status=pending */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', '');

        $rows = RefundRequest::query()
            ->with(['order:id,order_number,status,total,currency', 'user:id,name,email'])
            ->when(
                in_array($status, [
                    RefundRequest::STATUS_PENDING,
                    RefundRequest::STATUS_APPROVED,
                    RefundRequest::STATUS_REJECTED,
                    RefundRequest::STATUS_CANCELLED,
                ], true),
                fn ($q) => $q->where('status', $status)
            )
            ->latest()
            ->paginate(20);

        $meta = [
            'stats' => [
                'pending' => RefundRequest::query()->where('status', RefundRequest::STATUS_PENDING)->count(),
                'approved' => RefundRequest::query()->where('status', RefundRequest::STATUS_APPROVED)->count(),
                'rejected' => RefundRequest::query()->where('status', RefundRequest::STATUS_REJECTED)->count(),
            ],
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'total' => $rows->total(),
        ];

        return response()->json(['success' => true, 'data' => $rows->items(), 'meta' => $meta]);
    }

    /** POST /platform-admin/refund-requests/{refundRequest}/approve */
    public function approve(Request $request, RefundRequest $refundRequest): JsonResponse
    {
        // Cheap pre-lock check for the common "already reviewed" case — avoids
        // opening a transaction for a request that is obviously not pending. The
        // authoritative re-check happens INSIDE the lock below; this is only a
        // fast path, never the concurrency guard.
        if ($refundRequest->status !== RefundRequest::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending refund requests can be approved.',
            ], 422);
        }

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Audit C4 (§5) — make the whole approval idempotent and
        // concurrency-safe. We lock the RefundRequest row, RE-CHECK status ===
        // pending inside the lock (the cross-engine guard — lockForUpdate is a
        // no-op on SQLite but the in-txn re-read still serialises against any
        // other committed claim), CLAIM it by flipping to APPROVED, and only
        // THEN issue the gateway money-out. Two admins (or a double-click) both
        // entering here serialise on the row lock: exactly one sees pending and
        // proceeds; the loser sees non-pending and bails with NO gateway call.
        // If the gateway fails the whole transaction rolls back, so the claim is
        // released and the request is left pending for a retry.
        try {
            $result = DB::transaction(function () use ($refundRequest, $request, $validated): array {
                /** @var RefundRequest|null $locked */
                $locked = RefundRequest::query()
                    ->whereKey($refundRequest->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->status !== RefundRequest::STATUS_PENDING) {
                    // The loser of the race (or an already-reviewed request).
                    return ['outcome' => 'already_processed'];
                }

                $payment = $locked->refundablePayment();
                if ($payment === null) {
                    return ['outcome' => 'no_payment'];
                }

                // Partial when the customer asked for less than the payment total;
                // null = full refund.
                $amountCents = null;
                $requested = $locked->amount_requested !== null ? (float) $locked->amount_requested : null;
                $paymentAmount = (float) $payment->amount;
                $isFull = $requested === null || $requested >= $paymentAmount;
                if (! $isFull && $requested > 0) {
                    $amountCents = (int) round($requested * 100);
                }

                // CLAIM the request inside the lock BEFORE the money-out: a
                // concurrent transaction that somehow reaches the row will now
                // read APPROVED and bail. If the gateway throws below, this whole
                // transaction rolls back and the claim disappears.
                $locked->update([
                    'status' => RefundRequest::STATUS_APPROVED,
                    'admin_notes' => $validated['admin_notes'] ?? null,
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);

                // Gateway money-out — only ever reached by the claim winner.
                // Throws PaymentRefundFailedException on failure / over-cap,
                // which rolls back the claim above.
                $this->paymentService->refund($payment, $amountCents);

                // Full refund moves the order to the terminal 'refunded' status.
                if ($isFull) {
                    Order::query()->where('id', $locked->order_id)->update(['status' => 'refunded']);
                }

                return ['outcome' => 'approved'];
            });
        } catch (\Throwable $e) {
            Log::error('Refund-request approval: gateway refund failed', [
                'refund_request_id' => $refundRequest->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gateway refund failed: '.$e->getMessage(),
            ], 502);
        }

        if ($result['outcome'] === 'already_processed') {
            // 409 Conflict — the request was already claimed/reviewed by a
            // concurrent approval; no gateway call was made on this path.
            return response()->json([
                'success' => false,
                'message' => 'This refund request has already been processed.',
            ], 409);
        }

        if ($result['outcome'] === 'no_payment') {
            return response()->json([
                'success' => false,
                'message' => 'No paid payment found for this order to refund.',
            ], 422);
        }

        // Re-read so the notification sees the freshly-saved admin_notes (the
        // claim was made on a locked instance inside the transaction above).
        $refundRequest = $refundRequest->fresh() ?? $refundRequest;

        $this->notifyCustomer($refundRequest, 'refund.approved', 'Your refund was approved',
            'Your refund request has been approved and the money is being returned to your original payment method.');

        return response()->json([
            'success' => true,
            'data' => $refundRequest->fresh(['order', 'user']),
        ]);
    }

    /** POST /platform-admin/refund-requests/{refundRequest}/reject */
    public function reject(Request $request, RefundRequest $refundRequest): JsonResponse
    {
        if ($refundRequest->status !== RefundRequest::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending refund requests can be rejected.',
            ], 422);
        }

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $refundRequest->update([
            'status' => RefundRequest::STATUS_REJECTED,
            'admin_notes' => $validated['admin_notes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $this->notifyCustomer($refundRequest, 'refund.rejected', 'Your refund request was declined',
            'Your refund request has been reviewed and could not be approved. See the notes from our team for details.');

        return response()->json([
            'success' => true,
            'data' => $refundRequest->fresh(['order', 'user']),
        ]);
    }

    private function notifyCustomer(RefundRequest $refundRequest, string $event, string $title, string $message): void
    {
        try {
            $order = $refundRequest->order()->first();
            // Subject = the RefundRequest (integer id); the order id is a UUID,
            // which the notification's integer subject_id column can't hold.
            $this->notifications->createForEventWithEmail([
                'user_id' => $refundRequest->user_id,
                'event_type' => $event,
                'title' => $title,
                'message' => $message.($refundRequest->admin_notes ? "\n\nNote: ".$refundRequest->admin_notes : ''),
                'subject_type' => RefundRequest::class,
                'subject_id' => $refundRequest->id,
                'priority' => 'high',
                'variables' => ['order_number' => (string) ($order->order_number ?? '')],
            ]);
        } catch (\Throwable $e) {
            // Notification is best-effort — never block the refund decision.
            Log::warning('Refund-request notification failed', [
                'refund_request_id' => $refundRequest->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

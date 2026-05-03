<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-admin webhook oversight (Sprint 52, PART 30).
 *
 * Read-only endpoints for the admin webhook deliveries viewer.
 * Per-seller management is handled by SellerWebhookController.
 */
class AdminWebhookController extends Controller
{
    /**
     * GET /api/platform-admin/webhooks/subscriptions
     *
     * Lists all subscriptions across all companies. Optional filters:
     *   ?company_id=N
     *   ?event=order.paid
     *   ?status=active|paused
     */
    public function subscriptions(Request $request): JsonResponse
    {
        $query = WebhookSubscription::query()
            ->with('company:id,name')
            ->orderByDesc('created_at');

        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->query('company_id'));
        }

        if ($request->filled('event')) {
            $event = (string) $request->query('event');
            $query->whereJsonContains('events', $event);
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $rows = $query->limit(200)->get();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * GET /api/platform-admin/webhooks/deliveries
     *
     * Cross-subscription deliveries log. Optional filters:
     *   ?subscription_id=N
     *   ?status=pending|success|failed
     *   ?event=order.paid
     */
    public function deliveries(Request $request): JsonResponse
    {
        $perPage = max(10, min((int) $request->query('per_page', 50), 200));

        $query = WebhookDelivery::query()
            ->with('subscription:id,company_id,url')
            ->orderByDesc('created_at');

        if ($request->filled('subscription_id')) {
            $query->where('subscription_id', (int) $request->query('subscription_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('event')) {
            $query->where('event', (string) $request->query('event'));
        }

        $rows = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
            ],
        ]);
    }

    /**
     * POST /api/platform-admin/webhooks/deliveries/{id}/replay
     *
     * Resets a failed delivery to pending so the next dispatcher run picks
     * it up again. Resets attempt_count and clears error_message. The
     * existing payload is replayed verbatim — same idempotency_key, so
     * the receiver can deduplicate.
     */
    public function replayDelivery(int $id): JsonResponse
    {
        $delivery = WebhookDelivery::query()->find($id);
        if ($delivery === null) {
            return response()->json(['success' => false, 'message' => 'Delivery not found'], 404);
        }

        if ($delivery->status !== 'failed') {
            return response()->json([
                'success' => false,
                'message' => "Cannot replay a delivery in status '{$delivery->status}'",
            ], 422);
        }

        $delivery->status = 'pending';
        $delivery->attempt_count = 0;
        $delivery->error_message = null;
        $delivery->http_status = null;
        $delivery->response_excerpt = null;
        $delivery->save();

        return response()->json([
            'success' => true,
            'data' => $delivery,
        ]);
    }

    /**
     * GET /api/platform-admin/webhooks/dead-letter
     *
     * Convenience listing for failed deliveries (the dead-letter view).
     */
    public function deadLetter(Request $request): JsonResponse
    {
        $perPage = max(10, min((int) $request->query('per_page', 50), 200));

        $query = WebhookDelivery::query()
            ->with('subscription:id,company_id,url')
            ->where('status', 'failed')
            ->orderByDesc('last_attempted_at');

        if ($request->filled('subscription_id')) {
            $query->where('subscription_id', (int) $request->query('subscription_id'));
        }

        if ($request->filled('event')) {
            $query->where('event', (string) $request->query('event'));
        }

        $rows = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
            ],
        ]);
    }

    /**
     * GET /api/platform-admin/webhooks/stats
     *
     * Quick stats for admin dashboard.
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total_subscriptions' => WebhookSubscription::count(),
            'active_subscriptions' => WebhookSubscription::where('status', 'active')->count(),
            'deliveries_total' => WebhookDelivery::count(),
            'deliveries_success' => WebhookDelivery::where('status', 'success')->count(),
            'deliveries_failed' => WebhookDelivery::where('status', 'failed')->count(),
            'deliveries_pending' => WebhookDelivery::where('status', 'pending')->count(),
        ];

        $stats['success_rate'] = $stats['deliveries_total'] > 0
            ? round(($stats['deliveries_success'] / $stats['deliveries_total']) * 100, 1)
            : null;

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}

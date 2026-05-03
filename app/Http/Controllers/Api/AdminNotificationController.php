<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-admin notification oversight (Sprint 59, PART 23).
 *
 * Read-only listing across all users, plus per-event stats.
 */
class AdminNotificationController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    private function denyUnlessPlatformAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    /**
     * GET /api/platform-admin/notifications
     *
     * Filters: user_id, event_type, status (unread|read), priority,
     * from (ISO date), to (ISO date), q (substring on title/message).
     */
    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $query = Notification::query()
            ->with('user:id,name,email')
            ->orderByDesc('created_at');

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', (int) $userId);
        }

        if (is_string($eventType = $request->query('event_type')) && trim($eventType) !== '') {
            $query->where('event_type', trim($eventType));
        }

        if (is_string($status = $request->query('status')) && in_array($status, Notification::STATUSES, true)) {
            $query->where('status', $status);
        }

        if (is_string($priority = $request->query('priority')) && in_array($priority, Notification::PRIORITIES, true)) {
            $query->where('priority', $priority);
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }

        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $term = trim($q);
            $query->where(function ($qb) use ($term): void {
                $qb->where('title', 'like', "%{$term}%")
                    ->orWhere('message', 'like', "%{$term}%");
            });
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $paginator = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/platform-admin/notifications/stats
     *
     * Quick counters for the admin dashboard.
     */
    public function stats(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $byEvent = Notification::query()
            ->selectRaw('event_type, count(*) as count')
            ->groupBy('event_type')
            ->orderByDesc('count')
            ->limit(20)
            ->pluck('count', 'event_type');

        $byPriority = Notification::query()
            ->selectRaw('priority, count(*) as count')
            ->groupBy('priority')
            ->pluck('count', 'priority');

        return response()->json([
            'success' => true,
            'data' => [
                'total' => Notification::query()->count(),
                'unread' => Notification::query()->where('status', 'unread')->count(),
                'read' => Notification::query()->where('status', 'read')->count(),
                'by_event_type' => $byEvent,
                'by_priority' => $byPriority,
            ],
        ]);
    }
}

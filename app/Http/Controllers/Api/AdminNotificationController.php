<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendBulkNotificationJob;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserCompany;
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

    /**
     * Phase 7.6 — bulk send a single notification to many users.
     *
     * Accepts a recipient selector (all_b2c | all_staff | by_company |
     * specific_users) plus title + message and creates one Notification row
     * per matched user inside a single transaction. Super-admin gated.
     */
    public function bulkSend(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if ($authUser === null || ! $authUser->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'target' => ['required', 'string', 'in:all_b2c,all_staff,by_company,specific_users'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'priority' => ['nullable', 'string', 'in:low,normal,high'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', 'in:in_app,email,sms,push'],
        ]);

        $userIds = $this->resolveBulkRecipients($validated);
        if (count($userIds) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No recipients matched the selector.',
            ], 422);
        }

        $channels = $this->resolveChannels($validated['channels'] ?? null);

        SendBulkNotificationJob::dispatch(
            $userIds,
            $channels,
            (string) $validated['title'],
            (string) $validated['message'],
            (string) ($validated['priority'] ?? 'normal'),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'sent_count' => count($userIds),
                'channels' => $channels,
            ],
        ]);
    }

    /**
     * Normalise the channels selector. Empty / missing collapses to
     * ['in_app'] so older clients (and the API contract pre-channels)
     * keep delivering an in-app row by default.
     *
     * @param  mixed  $input
     * @return list<string>
     */
    private function resolveChannels($input): array
    {
        $allowed = ['in_app', 'email', 'sms', 'push'];
        if (! is_array($input) || $input === []) {
            return ['in_app'];
        }
        $picked = array_values(array_unique(array_filter(
            $input,
            static fn ($c) => in_array($c, $allowed, true),
        )));
        if ($picked === []) {
            return ['in_app'];
        }

        return $picked;
    }

    /** @param array<string, mixed> $validated @return list<int> */
    private function resolveBulkRecipients(array $validated): array
    {
        $target = $validated['target'];
        if ($target === 'specific_users') {
            return array_values(array_unique(array_map('intval', $validated['user_ids'] ?? [])));
        }
        if ($target === 'by_company') {
            $companyId = (int) ($validated['company_id'] ?? 0);
            if ($companyId <= 0) {
                return [];
            }

            return UserCompany::query()
                ->where('company_id', $companyId)
                ->pluck('user_id')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->all();
        }
        if ($target === 'all_b2c') {
            return User::query()
                ->whereDoesntHave('memberships')
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();
        }
        if ($target === 'all_staff') {
            return User::query()
                ->whereHas('memberships')
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();
        }

        return [];
    }
}

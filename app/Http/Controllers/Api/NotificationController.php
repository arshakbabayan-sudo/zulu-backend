<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\NotificationResource;
use App\Models\Notification;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use PaginatesCommerceResources;

    public function index(Request $request, NotificationService $notificationService): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $perPage = max(1, $perPage);
        $paginator = $notificationService->paginateForUser((int) $request->user()->id, $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, NotificationResource::class);
    }

    public function markRead(Request $request, Notification $notification, NotificationService $notificationService): JsonResponse
    {
        if ((int) $notification->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $updated = $notificationService->markAsRead($notification);

        return response()->json([
            'success' => true,
            'data' => NotificationResource::make($updated)->toArray($request),
        ]);
    }

    public function markAllRead(Request $request, NotificationService $service): JsonResponse
    {
        $count = $service->markAllReadForUser((int) $request->user()->id);

        return response()->json([
            'success' => true,
            'data' => ['updated_count' => $count],
        ]);
    }

    public function unreadCount(Request $request, NotificationService $service): JsonResponse
    {
        $count = $service->getUnreadCount((int) $request->user()->id);

        return response()->json([
            'success' => true,
            'data' => ['unread_count' => $count],
        ]);
    }

    public function paginatedIndex(Request $request, NotificationService $service): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $perPage = max(1, $perPage);
        $paginator = $service->paginateForUser((int) $request->user()->id, $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, NotificationResource::class);
    }
}

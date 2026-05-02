<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Admin\AdminAccessService;
use App\Services\Cart\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCartController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private CheckoutService $checkoutService,
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
     * GET /api/platform-admin/abandoned-carts
     * Lists carts (status='cart') that haven't been touched in N hours (default 24).
     * Filters: hours, per_page (default 25)
     */
    public function abandoned(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $hours = max(1, (int) $request->query('hours', 24));
        $cutoff = now()->subHours($hours);

        $query = Order::query()
            ->with(['user:id,name,email', 'items'])
            ->where('status', 'cart')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('updated_at');

        $perPage = min(max((int) $request->query('per_page', 25), 1), 200);
        $paginated = $query->paginate($perPage);

        return response()->json(['success' => true, 'data' => $paginated->items(), 'meta' => [
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'cutoff' => $cutoff->toIso8601String(),
            'hours' => $hours,
        ]]);
    }

    /**
     * POST /api/platform-admin/cart/release-expired-holds
     * Sweeps pending_payment orders whose held_until is in the past, resets to 'cart'.
     */
    public function releaseExpiredHolds(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $count = $this->checkoutService->releaseExpiredHolds();

        return response()->json(['success' => true, 'data' => ['released' => $count]]);
    }

    /**
     * GET /api/platform-admin/cart/stats
     * Counters for cart funnel.
     */
    public function stats(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'open_carts' => Order::query()->where('status', 'cart')->count(),
                'pending_payment' => Order::query()->where('status', 'pending_payment')->count(),
                'paid' => Order::query()->where('status', 'paid')->count(),
                'confirmed' => Order::query()->where('status', 'confirmed')->count(),
                'failed' => Order::query()->where('status', 'failed')->count(),
                'abandoned_24h' => Order::query()
                    ->where('status', 'cart')
                    ->where('updated_at', '<', now()->subDay())
                    ->count(),
            ],
        ]);
    }
}

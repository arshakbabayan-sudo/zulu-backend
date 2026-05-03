<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAccessService;
use App\Services\Analytics\PlatformStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPlatformStatisticsController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private PlatformStatisticsService $service,
    ) {}

    /** GET /api/platform-admin/statistics/dashboard?days=30 */
    public function dashboard(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $days = max(1, min(365, (int) $request->query('days', 30)));
        $snapshot = $this->service->dashboardSnapshot($days);

        return response()->json(['success' => true, 'data' => $snapshot]);
    }

    /** GET /api/platform-admin/statistics/revenue-series?days=30 */
    public function revenueSeries(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $days = max(1, min(365, (int) $request->query('days', 30)));

        return response()->json([
            'success' => true,
            'data' => $this->service->revenueTimeSeries($days),
        ]);
    }

    /** GET /api/platform-admin/statistics/orders-series?days=30 */
    public function ordersSeries(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $days = max(1, min(365, (int) $request->query('days', 30)));

        return response()->json([
            'success' => true,
            'data' => $this->service->ordersTimeSeries($days),
        ]);
    }

    /** GET /api/platform-admin/statistics/sellers?days=30&limit=20 */
    public function topSellers(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $days = max(1, min(365, (int) $request->query('days', 30)));
        $limit = max(1, min(100, (int) $request->query('limit', 20)));

        return response()->json([
            'success' => true,
            'data' => $this->service->topSellers($limit, $days),
        ]);
    }

    /** GET /api/platform-admin/statistics/sellers/{companyId}?days=30 */
    public function sellerDetail(Request $request, int $companyId): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $days = max(1, min(365, (int) $request->query('days', 30)));

        return response()->json([
            'success' => true,
            'data' => $this->service->perSellerStats($companyId, $days),
        ]);
    }

    private function denyUnlessPlatformAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }
}

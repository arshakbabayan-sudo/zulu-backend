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
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $days = max(1, min(365, (int) $request->query('days', 30)));
        $snapshot = $this->service->dashboardSnapshot($days);

        return response()->json(['success' => true, 'data' => $snapshot]);
    }
}

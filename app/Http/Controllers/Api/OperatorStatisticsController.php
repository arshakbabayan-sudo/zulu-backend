<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Services\Analytics\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperatorStatisticsController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function show(Request $request, StatisticsService $statisticsService): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $companyId = $this->adminAccessService->resolveOperatorStatisticsCompanyId($request, $user);

        if ($companyId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Company context is required for statistics.',
            ], 422);
        }

        $data = $statisticsService->buildOperatorCompanyStatisticsPayload($companyId, $request);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}

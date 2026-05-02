<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PackageBookingSaga;
use App\Services\Admin\AdminAccessService;
use App\Services\Packages\Saga\PackageBookingOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminPackageSagaController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private PackageBookingOrchestrator $orchestrator,
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
     * GET /api/platform-admin/package-sagas
     * Filters: status, order_id, package_id, per_page (default 25)
     */
    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $query = PackageBookingSaga::query()
            ->with(['order:id,order_number,user_id,total,currency', 'package:id,package_title']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($orderId = $request->query('order_id')) {
            $query->where('order_id', $orderId);
        }

        if ($packageId = $request->query('package_id')) {
            $query->where('package_id', (int) $packageId);
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 200);
        $paginated = $query->orderByDesc('started_at')->paginate($perPage);

        return response()->json(['success' => true] + $paginated->toArray());
    }

    /**
     * GET /api/platform-admin/package-sagas/{id}
     * Detail with components + state log.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $saga = PackageBookingSaga::query()
            ->with([
                'order',
                'package:id,package_title,package_type',
                'components',
                'logs',
            ])
            ->find($id);

        if ($saga === null) {
            return response()->json(['success' => false, 'message' => 'Saga not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $saga]);
    }

    /**
     * POST /api/platform-admin/package-sagas/{id}/retry
     * Re-runs the orchestrator for a saga's order. No-op if saga is already in a terminal state.
     */
    public function retry(Request $request, string $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $saga = PackageBookingSaga::query()->find($id);
        if ($saga === null) {
            return response()->json(['success' => false, 'message' => 'Saga not found'], 404);
        }

        $order = Order::query()->find($saga->order_id);
        if ($order === null) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        try {
            $resulting = $this->orchestrator->runForOrder($order);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'data' => $resulting]);
    }

    /**
     * GET /api/platform-admin/package-sagas/stats
     * Counts grouped by status.
     */
    public function stats(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $byStatus = PackageBookingSaga::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'success' => true,
            'data' => [
                'total' => PackageBookingSaga::query()->count(),
                'by_status' => $byStatus,
            ],
        ]);
    }
}

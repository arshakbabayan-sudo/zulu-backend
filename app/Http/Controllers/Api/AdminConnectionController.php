<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Admin\AdminAccessService;
use App\Services\Partnerships\PartnerConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class AdminConnectionController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private PartnerConnectionService $service,
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
     * GET /api/platform-admin/connections
     * Platform-wide listing with filters.
     * Query params: status, type, seller_id (matches either side), per_page (default 50)
     */
    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $query = Connection::query()
            ->with(['sellerA:id,name,type', 'sellerB:id,name,type', 'proposedBy:id,name,email']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($sellerId = $request->query('seller_id')) {
            $sellerId = (int) $sellerId;
            $query->where(function ($q) use ($sellerId): void {
                $q->where('seller_a_company_id', $sellerId)
                    ->orWhere('seller_b_company_id', $sellerId);
            });
        }

        // Tenant scope: a connection links two seller companies. Non-super
        // callers see only connections their own company is part of.
        $user = $request->user();
        if ($user !== null && ! $this->adminAccessService->isSuperAdmin($user)) {
            $ids = $this->adminAccessService->visibleCompanyIds($user) ?: [0];
            $query->where(function ($qb) use ($ids): void {
                $qb->whereIn('seller_a_company_id', $ids)->orWhereIn('seller_b_company_id', $ids);
            });
        }

        $perPage = min(max((int) $request->query('per_page', 50), 1), 200);
        $paginated = $query->orderByDesc('proposed_at')->paginate($perPage);

        return response()->json(['success' => true] + $paginated->toArray());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $connection = Connection::query()
            ->with([
                'sellerA:id,name,type',
                'sellerB:id,name,type',
                'partnerAgreement',
                'commissionOverrideRule',
                'proposedBy:id,name,email',
                'respondedBy:id,name,email',
            ])
            ->find($id);

        if ($connection === null) {
            return response()->json(['success' => false, 'message' => 'Connection not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $connection]);
    }

    /**
     * POST /api/platform-admin/connections/{id}/force-terminate
     * Admin-side termination. Requires reason.
     */
    public function forceTerminate(Request $request, string $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $connection = Connection::query()->find($id);
        if ($connection === null) {
            return response()->json(['success' => false, 'message' => 'Connection not found'], 404);
        }

        $reason = (string) $request->input('reason', '');
        if (trim($reason) === '') {
            return response()->json(['success' => false, 'message' => 'Termination reason is required'], 422);
        }

        try {
            $terminated = $this->service->terminate($connection, $request->user(), 'ADMIN: '.$reason);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $terminated]);
    }

    /**
     * GET /api/platform-admin/connections/stats
     * High-level counters for the admin dashboard.
     */
    public function stats(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $byStatus = Connection::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $byType = Connection::query()
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type');

        return response()->json([
            'success' => true,
            'data' => [
                'total' => Connection::query()->count(),
                'by_status' => $byStatus,
                'by_type' => $byType,
            ],
        ]);
    }
}

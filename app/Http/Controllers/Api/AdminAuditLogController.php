<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Admin\AdminAccessService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private AuditService $auditService,
    ) {}

    /**
     * GET /api/platform-admin/audit-logs
     * Filters: category, subject_type, subject_id, actor_id, action,
     * from (ISO date), to (ISO date), q (substring on action/subject_id).
     */
    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $query = AuditLog::query()->orderByDesc('created_at');

        if (is_string($category = $request->query('category')) && in_array($category, AuditLog::CATEGORIES, true)) {
            $query->where('category', $category);
        }

        if (is_string($subjectType = $request->query('subject_type')) && trim($subjectType) !== '') {
            $query->where('subject_type', trim($subjectType));
        }

        if (is_string($subjectId = $request->query('subject_id')) && trim($subjectId) !== '') {
            $query->where('subject_id', trim($subjectId));
        }

        if ($actorId = $request->query('actor_id')) {
            $query->where('actor_id', (int) $actorId);
        }

        if (is_string($action = $request->query('action')) && trim($action) !== '') {
            $query->where('action', trim($action));
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
                $qb->where('action', 'like', "%{$term}%")
                    ->orWhere('subject_id', 'like', "%{$term}%")
                    ->orWhere('actor_name_snapshot', 'like', "%{$term}%");
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
     * GET /api/platform-admin/audit-logs/{log}
     */
    public function show(Request $request, string $log): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $row = AuditLog::query()->find($log);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Audit log not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $row]);
    }

    /**
     * POST /api/platform-admin/audit-logs/verify-integrity
     * Returns IDs of any tampered rows (empty array = chain intact).
     */
    public function verifyIntegrity(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $limit = min(10000, max(1, (int) $request->query('limit', 1000)));
        $corrupted = $this->auditService->verifyIntegrity($limit);

        return response()->json([
            'success' => true,
            'data' => [
                'corrupted_log_ids' => $corrupted,
                'is_intact' => $corrupted === [],
                'limit_checked' => $limit,
            ],
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

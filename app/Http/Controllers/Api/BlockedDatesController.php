<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedDate;
use App\Models\Company;
use App\Services\Admin\AdminAccessService;
use App\Services\Admin\CompanyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 7.2 — operator-side blocked date management.
 *
 * Scopes everything to the auth user's active company. Super admin sees
 * all rows; staff users see their own company only.
 */
class BlockedDatesController extends Controller
{
    public function __construct(
        private CompanyAccessService $companyAccessService,
        private AdminAccessService $adminAccessService,
    ) {}

    /**
     * GET /api/blocked-dates
     * Optional ?item_type & ?item_id & ?from & ?to filters.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $query = BlockedDate::query()
            ->with(['createdBy:id,name', 'company:id,name'])
            ->orderBy('blocked_from');

        if (! $this->adminAccessService->isSuperAdmin($user)) {
            $companyIds = $user->companies()->pluck('companies.id')->all();
            $query->whereIn('company_id', $companyIds);
        }

        if (is_string($type = $request->query('item_type')) && in_array($type, BlockedDate::ITEM_TYPES, true)) {
            $query->where('item_type', $type);
        }
        if ($request->filled('item_id')) {
            $query->where('item_id', (int) $request->query('item_id'));
        }
        if ($request->filled('from')) {
            $query->where('blocked_to', '>=', (string) $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('blocked_from', '<=', (string) $request->query('to'));
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $paginator = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $paginator->getCollection()->map(fn ($r) => $this->serialize($r))->values()->all(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/blocked-dates
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'item_type' => ['required', Rule::in(BlockedDate::ITEM_TYPES)],
            'item_id' => ['nullable', 'integer', 'min:1'],
            'blocked_from' => ['required', 'date'],
            'blocked_to' => ['required', 'date', 'after_or_equal:blocked_from'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $companyId = $user->companies()->pluck('companies.id')->first();
        if (! $companyId) {
            return response()->json([
                'success' => false,
                'message' => 'No active company on this user.',
            ], 422);
        }

        $row = BlockedDate::query()->create([
            'company_id' => $companyId,
            'item_type' => $validated['item_type'],
            'item_id' => $validated['item_id'] ?? null,
            'blocked_from' => $validated['blocked_from'],
            'blocked_to' => $validated['blocked_to'],
            'reason' => $validated['reason'] ?? null,
            'created_by_user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['createdBy', 'company'])),
        ], 201);
    }

    /**
     * DELETE /api/blocked-dates/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $row = BlockedDate::query()->find($id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        if (! $this->adminAccessService->isSuperAdmin($user)) {
            $companyIds = $user->companies()->pluck('companies.id')->all();
            if (! in_array((int) $row->company_id, $companyIds, true)) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }

        $row->delete();

        return response()->json(['success' => true, 'data' => ['deleted_id' => $id]]);
    }

    /** @return array<string, mixed> */
    private function serialize(BlockedDate $row): array
    {
        return [
            'id' => $row->id,
            'company_id' => $row->company_id,
            'company_name' => $row->company?->name,
            'item_type' => $row->item_type,
            'item_id' => $row->item_id,
            'blocked_from' => $row->blocked_from?->format('Y-m-d'),
            'blocked_to' => $row->blocked_to?->format('Y-m-d'),
            'reason' => $row->reason,
            'created_by' => $row->createdBy ? ['id' => $row->createdBy->id, 'name' => $row->createdBy->name] : null,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}

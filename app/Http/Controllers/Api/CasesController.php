<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminCase;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 7.10 — cases CRUD + assignment endpoints.
 *
 * Visibility rules:
 * - Super admin / platform admin: see all cases.
 * - Staff: see cases opened by them, assigned to them, or scoped to a
 *   company they belong to.
 */
class CasesController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $query = AdminCase::query()
            ->with(['assignedTo:id,name', 'openedBy:id,name', 'company:id,name'])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at');

        if (! $this->adminAccessService->isPlatformAdmin($user) && ! $user->is_super_admin) {
            $companyIds = $user->companies()->pluck('companies.id')->all();
            $query->where(function ($q) use ($user, $companyIds): void {
                $q->where('opened_by_user_id', $user->id)
                    ->orWhere('assigned_to_user_id', $user->id);
                if (! empty($companyIds)) {
                    $q->orWhereIn('company_id', $companyIds);
                }
            });
        }

        if (in_array($status = (string) $request->query('status'), AdminCase::STATUSES, true)) {
            $query->where('status', $status);
        }
        if (in_array($priority = (string) $request->query('priority'), AdminCase::PRIORITIES, true)) {
            $query->where('priority', $priority);
        }
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to_user_id', (int) $request->query('assigned_to'));
        }
        if ($request->filled('search')) {
            $term = (string) $request->query('search');
            $query->where(function ($q) use ($term): void {
                $q->where('case_number', 'like', "%{$term}%")
                    ->orWhere('title', 'like', "%{$term}%");
            });
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 25)));
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

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'priority' => ['nullable', Rule::in(AdminCase::PRIORITIES)],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $caseNumber = 'C-'.now()->format('Ymd').'-'.strtoupper(substr(md5(uniqid('', true)), 0, 6));

        $row = AdminCase::query()->create([
            'case_number' => $caseNumber,
            'company_id' => $validated['company_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'status' => 'open',
            'priority' => $validated['priority'] ?? 'normal',
            'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
            'opened_by_user_id' => $user->id,
            'opened_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['assignedTo', 'openedBy', 'company'])),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $row = AdminCase::query()->find($id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(AdminCase::STATUSES)],
            'priority' => ['sometimes', Rule::in(AdminCase::PRIORITIES)],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'closing_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        if (isset($validated['status'])) {
            if (in_array($validated['status'], ['closed', 'resolved'], true) && $row->closed_at === null) {
                $row->closed_at = now();
            } elseif (! in_array($validated['status'], ['closed', 'resolved'], true)) {
                $row->closed_at = null;
            }
        }

        $row->fill($validated);
        $row->save();

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['assignedTo', 'openedBy', 'company'])),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $row = AdminCase::query()
            ->with(['assignedTo', 'openedBy', 'company'])
            ->find($id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->serialize($row)]);
    }

    /** @return array<string, mixed> */
    private function serialize(AdminCase $row): array
    {
        return [
            'id' => $row->id,
            'case_number' => $row->case_number,
            'company_id' => $row->company_id,
            'company_name' => $row->company?->name,
            'title' => $row->title,
            'description' => $row->description,
            'status' => $row->status,
            'priority' => $row->priority,
            'assigned_to' => $row->assignedTo
                ? ['id' => $row->assignedTo->id, 'name' => $row->assignedTo->name]
                : null,
            'opened_by' => $row->openedBy
                ? ['id' => $row->openedBy->id, 'name' => $row->openedBy->name]
                : null,
            'opened_at' => $row->opened_at?->toIso8601String(),
            'closed_at' => $row->closed_at?->toIso8601String(),
            'closing_notes' => $row->closing_notes,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}

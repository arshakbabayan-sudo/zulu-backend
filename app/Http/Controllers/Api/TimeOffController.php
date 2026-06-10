<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeOffRecord;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 7.13 — time-off request workflow + non-service-hours log.
 *
 * Workflow: employee creates a `pending` request → manager approves /
 * rejects. Admin / super admin can act on behalf of either side.
 */
class TimeOffController extends Controller
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

        $query = TimeOffRecord::query()
            ->with(['user:id,name,email', 'company:id,name', 'decidedBy:id,name'])
            ->orderByDesc('starts_on');

        if (! $this->adminAccessService->isSuperAdmin($user)) {
            $companyIds = $user->companies()->pluck('companies.id')->all();
            $query->whereIn('company_id', $companyIds);
        }

        if (in_array($status = (string) $request->query('status'), TimeOffRecord::STATUSES, true)) {
            $query->where('status', $status);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
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

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'type' => ['required', Rule::in(TimeOffRecord::TYPES)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'hours_total' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $companyId = $user->companies()->pluck('companies.id')->first();
        if (! $companyId) {
            return response()->json(['success' => false, 'message' => 'No active company.'], 422);
        }

        $row = TimeOffRecord::query()->create([
            'company_id' => $companyId,
            'user_id' => $validated['user_id'] ?? $user->id,
            'type' => $validated['type'],
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'],
            'hours_total' => $validated['hours_total'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['user', 'company'])),
        ], 201);
    }

    /**
     * Edit an existing time-off entry (the work-hours "edit" action).
     *
     * Auth mirrors store()/decide(): the creator may edit their OWN entry while
     * it is still `pending`; a company manager (member of the entry's company)
     * or a super admin may edit at any time (decide-level auth). Anyone else is
     * forbidden. Editable fields are exactly what store() accepts (type, dates,
     * hours, notes). Status is NOT mutated here — that stays on decide().
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $row = TimeOffRecord::query()->find($id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $isSuper = $this->adminAccessService->isSuperAdmin($user);
        // Manager = belongs to the entry's company (same tenant scope index() uses).
        $isManager = $isSuper || $user->companies()->whereKey($row->company_id)->exists();
        $isOwner = $row->user_id === $user->id;

        if (! $isOwner && ! $isManager) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        // A plain creator may only edit while pending; a manager/super may edit
        // a decided entry (decide-level auth).
        if (! $isManager && $row->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending entries can be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'type' => ['sometimes', 'required', Rule::in(TimeOffRecord::TYPES)],
            'starts_on' => ['sometimes', 'required', 'date'],
            'ends_on' => ['sometimes', 'required', 'date', 'after_or_equal:starts_on'],
            'hours_total' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        foreach (['type', 'starts_on', 'ends_on', 'hours_total', 'notes'] as $field) {
            if (array_key_exists($field, $validated)) {
                $row->{$field} = $validated[$field];
            }
        }
        $row->save();

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['user', 'company', 'decidedBy'])),
        ]);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $row = TimeOffRecord::query()->find($id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected', 'cancelled'])],
            'decision_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $row->status = $validated['status'];
        $row->decided_by_user_id = $request->user()->id;
        $row->decided_at = now();
        $row->decision_notes = $validated['decision_notes'] ?? $row->decision_notes;
        $row->save();

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['user', 'company', 'decidedBy'])),
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(TimeOffRecord $row): array
    {
        return [
            'id' => $row->id,
            'company_id' => $row->company_id,
            'company_name' => $row->company?->name,
            'user' => $row->user ? ['id' => $row->user->id, 'name' => $row->user->name, 'email' => $row->user->email] : null,
            'type' => $row->type,
            'starts_on' => $row->starts_on?->format('Y-m-d'),
            'ends_on' => $row->ends_on?->format('Y-m-d'),
            'hours_total' => $row->hours_total !== null ? (float) $row->hours_total : null,
            'notes' => $row->notes,
            'status' => $row->status,
            'decided_by' => $row->decidedBy ? ['id' => $row->decidedBy->id, 'name' => $row->decidedBy->name] : null,
            'decided_at' => $row->decided_at?->toIso8601String(),
            'decision_notes' => $row->decision_notes,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}

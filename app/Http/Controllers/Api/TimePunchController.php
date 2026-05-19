<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimePunch;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Clock-in / clock-out tracking. Sibling of TimeOffController — that one
 * tracks *planned absences*, this one tracks *actual shift attendance*.
 *
 * Endpoints:
 *   GET    /api/time-punches               list with filters
 *   POST   /api/time-punches/clock-in      open a shift for the caller
 *   POST   /api/time-punches/{id}/clock-out  close an open shift
 *   POST   /api/time-punches               manager-entered explicit row
 */
class TimePunchController extends Controller
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

        $query = TimePunch::query()
            ->with(['user:id,name,email', 'company:id,name'])
            ->orderByDesc('punched_in_at');

        if (! $this->adminAccessService->isSuperAdmin($user)) {
            $companyIds = $user->companies()->pluck('companies.id')->all();
            $query->whereIn('company_id', $companyIds);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }
        if ($request->filled('date_from')) {
            $query->where('punched_in_at', '>=', (string) $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('punched_in_at', '<=', (string) $request->query('date_to'));
        }
        if ((string) $request->query('open') === '1') {
            $query->whereNull('punched_out_at');
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

    public function clockIn(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $companyId = $user->companies()->pluck('companies.id')->first();
        if (! $companyId) {
            return response()->json(['success' => false, 'message' => 'No active company.'], 422);
        }

        $existing = TimePunch::query()
            ->where('user_id', $user->id)
            ->whereNull('punched_out_at')
            ->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Already clocked in. Clock out first.',
                'data' => $this->serialize($existing->fresh(['user', 'company'])),
            ], 409);
        }

        $row = TimePunch::query()->create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'punched_in_at' => now(),
            'source' => 'self',
            'created_by_user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['user', 'company'])),
        ], 201);
    }

    public function clockOut(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $row = TimePunch::query()->find($id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        if (! $this->canAccessRecord($user, $row)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if ($row->punched_out_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Shift already closed.',
                'data' => $this->serialize($row->fresh(['user', 'company'])),
            ], 409);
        }

        $now = now();
        $row->punched_out_at = $now;
        $row->minutes_worked = max(0, (int) $row->punched_in_at->diffInMinutes($now));
        $row->save();

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['user', 'company'])),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'punched_in_at' => ['required', 'date'],
            'punched_out_at' => ['nullable', 'date', 'after:punched_in_at'],
            'source' => ['nullable', Rule::in(TimePunch::SOURCES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $companyId = $user->companies()->pluck('companies.id')->first();
        if (! $companyId) {
            return response()->json(['success' => false, 'message' => 'No active company.'], 422);
        }

        $in = \Carbon\Carbon::parse($validated['punched_in_at']);
        $out = isset($validated['punched_out_at']) ? \Carbon\Carbon::parse($validated['punched_out_at']) : null;
        $minutes = $out ? max(0, (int) $in->diffInMinutes($out)) : null;

        $row = TimePunch::query()->create([
            'company_id' => $companyId,
            'user_id' => $validated['user_id'],
            'punched_in_at' => $in,
            'punched_out_at' => $out,
            'minutes_worked' => $minutes,
            'source' => $validated['source'] ?? 'manager',
            'created_by_user_id' => $user->id,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['user', 'company'])),
        ], 201);
    }

    private function canAccessRecord(\App\Models\User $user, TimePunch $row): bool
    {
        if ($this->adminAccessService->isSuperAdmin($user)) {
            return true;
        }
        if ($row->user_id === $user->id) {
            return true;
        }
        $companyIds = $user->companies()->pluck('companies.id')->all();

        return in_array($row->company_id, $companyIds, true);
    }

    /** @return array<string, mixed> */
    private function serialize(TimePunch $row): array
    {
        return [
            'id' => $row->id,
            'company_id' => $row->company_id,
            'company_name' => $row->company?->name,
            'user' => $row->user ? ['id' => $row->user->id, 'name' => $row->user->name, 'email' => $row->user->email] : null,
            'punched_in_at' => $row->punched_in_at?->toIso8601String(),
            'punched_out_at' => $row->punched_out_at?->toIso8601String(),
            'minutes_worked' => $row->minutes_worked,
            'is_open' => $row->isOpen(),
            'source' => $row->source,
            'notes' => $row->notes,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}

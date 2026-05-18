<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollRecord;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 7.15 — payroll CRUD + finalize / mark-paid endpoints.
 *
 * Payslip PDF generation + bank-batch export are follow-ups; this lands
 * the ledger and lifecycle (draft → finalized → paid).
 */
class PayrollController extends Controller
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

        $query = PayrollRecord::query()
            ->with(['user:id,name,email', 'company:id,name'])
            ->orderByDesc('period_start')
            ->orderBy('user_id');

        if (! $this->adminAccessService->isSuperAdmin($user)) {
            $companyIds = $user->companies()->pluck('companies.id')->all();
            $query->whereIn('company_id', $companyIds);
        }

        if (in_array($status = (string) $request->query('status'), PayrollRecord::STATUSES, true)) {
            $query->where('status', $status);
        }
        if ($request->filled('period_start')) {
            $query->where('period_start', '>=', (string) $request->query('period_start'));
        }
        if ($request->filled('period_end')) {
            $query->where('period_end', '<=', (string) $request->query('period_end'));
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'base_salary' => ['nullable', 'numeric', 'min:0'],
            'hours_worked' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'commission_amount' => ['nullable', 'numeric', 'min:0'],
            'bonus_amount' => ['nullable', 'numeric', 'min:0'],
            'deductions_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $companyId = $user->companies()->pluck('companies.id')->first();
        if (! $companyId) {
            return response()->json(['success' => false, 'message' => 'No active company.'], 422);
        }

        $base = (float) ($validated['base_salary'] ?? 0);
        $hourly = (float) ($validated['hourly_rate'] ?? 0);
        $hours = (float) ($validated['hours_worked'] ?? 0);
        $commission = (float) ($validated['commission_amount'] ?? 0);
        $bonus = (float) ($validated['bonus_amount'] ?? 0);
        $deductions = (float) ($validated['deductions_amount'] ?? 0);

        $gross = $base + $hourly * $hours + $commission + $bonus;
        $net = max(0.0, $gross - $deductions);

        $row = PayrollRecord::query()->create([
            'company_id' => $companyId,
            'user_id' => $validated['user_id'],
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'base_salary' => $base,
            'hours_worked' => $validated['hours_worked'] ?? null,
            'hourly_rate' => $validated['hourly_rate'] ?? null,
            'commission_amount' => $commission,
            'bonus_amount' => $bonus,
            'deductions_amount' => $deductions,
            'gross_pay' => $gross,
            'net_pay' => $net,
            'currency' => isset($validated['currency']) ? strtoupper($validated['currency']) : 'USD',
            'status' => 'draft',
            'notes' => $validated['notes'] ?? null,
            'created_by_user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['user', 'company'])),
        ], 201);
    }

    public function changeStatus(Request $request, int $id): JsonResponse
    {
        $row = PayrollRecord::query()->find($id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(PayrollRecord::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $row->status = $validated['status'];
        if ($validated['status'] === 'paid' && $row->paid_at === null) {
            $row->paid_at = now();
        }
        if ($validated['status'] !== 'paid') {
            $row->paid_at = null;
        }
        if (isset($validated['notes'])) {
            $row->notes = $validated['notes'];
        }
        $row->save();

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh(['user', 'company'])),
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(PayrollRecord $row): array
    {
        return [
            'id' => $row->id,
            'company_id' => $row->company_id,
            'company_name' => $row->company?->name,
            'user' => $row->user ? ['id' => $row->user->id, 'name' => $row->user->name, 'email' => $row->user->email] : null,
            'period_start' => $row->period_start?->format('Y-m-d'),
            'period_end' => $row->period_end?->format('Y-m-d'),
            'base_salary' => (float) $row->base_salary,
            'hours_worked' => $row->hours_worked !== null ? (float) $row->hours_worked : null,
            'hourly_rate' => $row->hourly_rate !== null ? (float) $row->hourly_rate : null,
            'commission_amount' => (float) $row->commission_amount,
            'bonus_amount' => (float) $row->bonus_amount,
            'deductions_amount' => (float) $row->deductions_amount,
            'gross_pay' => (float) $row->gross_pay,
            'net_pay' => (float) $row->net_pay,
            'currency' => $row->currency,
            'status' => $row->status,
            'paid_at' => $row->paid_at?->toIso8601String(),
            'notes' => $row->notes,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}

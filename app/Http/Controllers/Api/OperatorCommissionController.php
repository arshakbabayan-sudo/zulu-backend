<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\OperatorAgentCommission;
use App\Services\Admin\AdminAccessService;
use App\Services\Admin\CompanyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 6B — operator-side commission settings for downstream agents.
 *
 * Endpoints all act on the user's active operator company (resolved from
 * the auth user's membership). Anyone with manage rights for that company
 * can read; only super admins and company admins can write.
 */
class OperatorCommissionController extends Controller
{
    public function __construct(
        private CompanyAccessService $companyAccessService,
        private AdminAccessService $adminAccessService,
    ) {}

    /**
     * GET /api/operator/commission-settings
     * Returns the default config (or null if not set) plus all per-agent
     * overrides for the user's active operator company.
     */
    public function index(Request $request): JsonResponse
    {
        $company = $this->resolveActiveOperator($request);
        if ($company === null) {
            return response()->json([
                'success' => false,
                'message' => 'No active operator company on this user.',
            ], 404);
        }

        $rows = OperatorAgentCommission::query()
            ->where('operator_company_id', $company->id)
            ->with('agentCompany:id,name')
            ->orderBy('agent_company_id')
            ->get();

        $default = $rows->firstWhere('agent_company_id', null);
        $overrides = $rows->filter(fn ($r) => $r->agent_company_id !== null)->values();

        return response()->json([
            'success' => true,
            'data' => [
                'operator_company_id' => $company->id,
                'available_bases' => OperatorAgentCommission::CALCULATION_BASES,
                'default' => $default ? $this->serialize($default) : null,
                'overrides' => $overrides->map(fn ($r) => $this->serialize($r))->values()->all(),
            ],
        ]);
    }

    /**
     * PATCH /api/operator/commission-settings
     * Upserts the default config for the operator. Pass `null` for any
     * decimal field to clear it.
     */
    public function upsertDefault(Request $request): JsonResponse
    {
        $company = $this->resolveActiveOperator($request);
        if ($company === null) {
            return response()->json(['success' => false, 'message' => 'No active operator company.'], 404);
        }
        if ($deny = $this->denyUnlessCanManage($request, $company)) {
            return $deny;
        }

        $validated = $request->validate([
            'calculation_base' => ['required', Rule::in(OperatorAgentCommission::CALCULATION_BASES)],
            'default_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'custom_base_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        OperatorAgentCommission::query()->updateOrCreate(
            ['operator_company_id' => $company->id, 'agent_company_id' => null],
            [
                'calculation_base' => $validated['calculation_base'],
                'default_percentage' => $validated['default_percentage'] ?? null,
                'custom_base_percentage' => $validated['custom_base_percentage'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by_user_id' => $request->user()->id,
            ]
        );

        return $this->index($request);
    }

    /**
     * PATCH /api/operator/commission-settings/agents/{agent}
     * Upserts a per-agent override row.
     */
    public function upsertOverride(Request $request, Company $agent): JsonResponse
    {
        $company = $this->resolveActiveOperator($request);
        if ($company === null) {
            return response()->json(['success' => false, 'message' => 'No active operator company.'], 404);
        }
        if ($deny = $this->denyUnlessCanManage($request, $company)) {
            return $deny;
        }
        if ($agent->id === $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Operator cannot set commission overrides against itself.',
            ], 422);
        }

        $validated = $request->validate([
            'calculation_base' => ['required', Rule::in(OperatorAgentCommission::CALCULATION_BASES)],
            'default_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'custom_base_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        OperatorAgentCommission::query()->updateOrCreate(
            ['operator_company_id' => $company->id, 'agent_company_id' => $agent->id],
            [
                'calculation_base' => $validated['calculation_base'],
                'default_percentage' => $validated['default_percentage'] ?? null,
                'custom_base_percentage' => $validated['custom_base_percentage'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by_user_id' => $request->user()->id,
            ]
        );

        return $this->index($request);
    }

    /**
     * DELETE /api/operator/commission-settings/agents/{agent}
     */
    public function deleteOverride(Request $request, Company $agent): JsonResponse
    {
        $company = $this->resolveActiveOperator($request);
        if ($company === null) {
            return response()->json(['success' => false, 'message' => 'No active operator company.'], 404);
        }
        if ($deny = $this->denyUnlessCanManage($request, $company)) {
            return $deny;
        }

        OperatorAgentCommission::query()
            ->where('operator_company_id', $company->id)
            ->where('agent_company_id', $agent->id)
            ->delete();

        return $this->index($request);
    }

    private function resolveActiveOperator(Request $request): ?Company
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }
        $user->loadMissing('companies');
        $first = $user->companies->sortBy('id')->first();

        return $first instanceof Company ? $first : null;
    }

    private function denyUnlessCanManage(Request $request, Company $company): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }
        if ($this->adminAccessService->isSuperAdmin($user)) {
            return null;
        }
        if ($this->companyAccessService->canManageCompany($user, $company)) {
            return null;
        }

        return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
    }

    /** @return array<string, mixed> */
    private function serialize(OperatorAgentCommission $row): array
    {
        return [
            'id' => $row->id,
            'agent_company_id' => $row->agent_company_id,
            'agent_company_name' => $row->agentCompany?->name,
            'calculation_base' => $row->calculation_base,
            'default_percentage' => $row->default_percentage !== null ? (float) $row->default_percentage : null,
            'custom_base_percentage' => $row->custom_base_percentage !== null ? (float) $row->custom_base_percentage : null,
            'notes' => $row->notes,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}

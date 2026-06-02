<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Services\Admin\AdminAccessService;
use App\Services\Contracts\ContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminContractController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private ContractService $contractService,
    ) {}

    /**
     * GET /api/platform-admin/contracts
     */
    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $query = Contract::query()
            ->with(['partyA:id,name', 'partyB:id,name', 'template:id,name,type,language,version'])
            ->orderByDesc('created_at');

        if (is_string($status = $request->query('status')) && in_array($status, Contract::STATUSES, true)) {
            $query->where('status', $status);
        }

        if (is_string($type = $request->query('type')) && in_array($type, Contract::TYPES, true)) {
            $query->where('type', $type);
        }

        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $term = trim($q);
            $query->where('contract_number', 'like', "%{$term}%");
        }

        // Tenant scope: a contract belongs to either party company. Non-super
        // callers see only contracts their own company is a party to.
        $user = $request->user();
        if ($user !== null && ! $this->adminAccessService->isSuperAdmin($user)) {
            $ids = $this->adminAccessService->visibleCompanyIds($user) ?: [0];
            $query->where(function ($qb) use ($ids): void {
                $qb->whereIn('party_a_company_id', $ids)->orWhereIn('party_b_company_id', $ids);
            });
            // Phase 3 Layer-B: a plain employee sees only contracts they
            // created (ContractService stamps created_by_user_id); owner /
            // contracts.view_all holder sees the whole company.
            $rowScopeUserId = $this->adminAccessService->employeeRowScopeUserId($user, 'contracts.view_all');
            if ($rowScopeUserId !== null) {
                $query->where('created_by_user_id', $rowScopeUserId);
            }
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
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
     * GET /api/platform-admin/contracts/{contract}
     */
    public function show(Request $request, string $contract): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $model = Contract::query()
            ->with(['partyA:id,name', 'partyB:id,name', 'template', 'createdBy:id,name,email', 'versions'])
            ->find($contract);

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Contract not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $model]);
    }

    /**
     * POST /api/platform-admin/contracts
     * Generates a new contract from a template.
     */
    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $data = $request->validate([
            'template_id' => 'required|uuid|exists:contract_templates,id',
            'party_a_company_id' => 'nullable|integer|exists:companies,id',
            'party_b_company_id' => 'required|integer|exists:companies,id',
            'variables' => 'nullable|array',
            'commission_clause' => 'nullable|array',
            'payment_terms' => 'nullable|array',
            'cancellation_policy' => 'nullable|array',
            'effective_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after_or_equal:effective_date',
            'auto_renew' => 'sometimes|boolean',
            'termination_notice_days' => 'sometimes|integer|min:0|max:365',
            'language' => ['sometimes', 'string', Rule::in(Contract::LANGUAGES)],
        ]);

        $template = ContractTemplate::query()->findOrFail($data['template_id']);
        $partyA = ! empty($data['party_a_company_id']) ? Company::query()->findOrFail($data['party_a_company_id']) : null;
        $partyB = Company::query()->findOrFail($data['party_b_company_id']);

        try {
            $contract = $this->contractService->generateFromTemplate(
                $template,
                $partyA,
                $partyB,
                $request->user(),
                $data,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $contract], 201);
    }

    /**
     * POST /api/platform-admin/contracts/{contract}/send
     */
    public function send(Request $request, string $contract): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $model = Contract::query()->find($contract);
        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Contract not found'], 404);
        }

        try {
            $sent = $this->contractService->send($model, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $sent]);
    }

    /**
     * POST /api/platform-admin/contracts/{contract}/countersign
     * ZULU side counter-signature on a Platform Contract (party_a).
     */
    public function countersign(Request $request, string $contract): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $model = Contract::query()->find($contract);
        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Contract not found'], 404);
        }

        try {
            $signed = $this->contractService->signAsPartyA(
                $model,
                $request->user(),
                (string) $request->ip(),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $signed]);
    }

    /**
     * POST /api/platform-admin/contracts/{contract}/terminate
     */
    public function terminate(Request $request, string $contract): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $data = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $model = Contract::query()->find($contract);
        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Contract not found'], 404);
        }

        try {
            $terminated = $this->contractService->terminate($model, $data['reason'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $terminated]);
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

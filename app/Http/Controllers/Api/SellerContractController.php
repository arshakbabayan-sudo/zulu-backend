<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\Contracts\ContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerContractController extends Controller
{
    public function __construct(
        private ContractService $contractService,
    ) {}

    /**
     * GET /api/seller/contracts
     * Lists contracts where the authenticated user belongs to either party_a
     * or party_b company. Eager-loads template + parties for display.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyIds = $user->companies()->pluck('companies.id')->all();

        if ($companyIds === []) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $contracts = Contract::query()
            ->with(['partyA:id,name', 'partyB:id,name', 'template:id,name,type,language,version'])
            ->where(function ($q) use ($companyIds): void {
                $q->whereIn('party_a_company_id', $companyIds)
                    ->orWhereIn('party_b_company_id', $companyIds);
            })
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $contracts,
        ]);
    }

    /**
     * GET /api/seller/contracts/{contract}
     * Detail with version history. Returns 404 if the seller is not party
     * to the contract.
     */
    public function show(Request $request, string $contract): JsonResponse
    {
        $model = $this->resolveOwnedContract($request, $contract);
        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Contract not found'], 404);
        }

        $model->load(['partyA:id,name', 'partyB:id,name', 'template', 'versions']);

        return response()->json(['success' => true, 'data' => $model]);
    }

    /**
     * POST /api/seller/contracts/{contract}/sign
     * Seller signs as party_b. Idempotent — returns existing record if
     * already signed by this party.
     */
    public function sign(Request $request, string $contract): JsonResponse
    {
        $model = $this->resolveOwnedContract($request, $contract);
        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Contract not found'], 404);
        }

        $userCompanies = $request->user()->companies()->pluck('companies.id')->all();
        $signsAsB = in_array((int) $model->party_b_company_id, $userCompanies, true);
        $signsAsA = $model->party_a_company_id !== null
            && in_array((int) $model->party_a_company_id, $userCompanies, true);

        if (! $signsAsA && ! $signsAsB) {
            return response()->json(['success' => false, 'message' => 'You are not a party to this contract'], 403);
        }

        try {
            $signed = $signsAsB
                ? $this->contractService->signAsPartyB($model, $request->user(), (string) $request->ip())
                : $this->contractService->signAsPartyA($model, $request->user(), (string) $request->ip());
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $signed]);
    }

    private function resolveOwnedContract(Request $request, string $contractId): ?Contract
    {
        $userCompanies = $request->user()->companies()->pluck('companies.id')->all();
        if ($userCompanies === []) {
            return null;
        }

        return Contract::query()
            ->where(function ($q) use ($userCompanies): void {
                $q->whereIn('party_a_company_id', $userCompanies)
                    ->orWhereIn('party_b_company_id', $userCompanies);
            })
            ->find($contractId);
    }
}

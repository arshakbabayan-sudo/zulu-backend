<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CommissionPolicyResource;
use App\Http\Resources\Api\CommissionRecordResource;
use App\Models\CommissionPolicy;
use App\Models\Company;
use App\Services\Admin\AdminAccessService;
use App\Services\Commissions\CommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function index(Request $request, CommissionService $commissionService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'commissions.view');

        if (! $request->filled('page')) {
            $commissions = $commissionService->listForCompanies($companyIds);

            return response()->json([
                'success' => true,
                'data' => CommissionPolicyResource::collection($commissions)->resolve(),
            ]);
        }

        $paginator = $commissionService->paginateForCompanies($companyIds, $this->commerceListPerPage($request));

        return $this->paginatedCommerceResourceResponse($request, $paginator, CommissionPolicyResource::class);
    }

    public function indexRecords(Request $request, CommissionService $commissionService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'commission_records.view');

        if (! $request->filled('page')) {
            $records = $commissionService->listRecordsForCompanies($companyIds);

            return response()->json([
                'success' => true,
                'data' => CommissionRecordResource::collection($records)->resolve(),
            ]);
        }

        $paginator = $commissionService->paginateRecordsForCompanies($companyIds, $this->commerceListPerPage($request));

        return $this->paginatedCommerceResourceResponse($request, $paginator, CommissionRecordResource::class);
    }

    public function show(Request $request, CommissionPolicy $commission): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $commission->company_id, 'commissions.view')) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => CommissionPolicyResource::make($commission)->toArray($request),
        ]);
    }

    public function createPolicy(Request $request, CommissionService $service): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $company = Company::query()->findOrFail((int) $validated['company_id']);

        if ($response = $this->ensureCommerceAccess($request, (int) $company->id, 'commissions.create')) {
            return $response;
        }

        $policy = $service->createPolicy($company, $request->all());

        return response()->json([
            'success' => true,
            'data' => CommissionPolicyResource::make($policy)->toArray($request),
        ], 201);
    }

    public function updatePolicy(Request $request, CommissionPolicy $commission, CommissionService $service): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $commission->company_id, 'commissions.update')) {
            return $response;
        }

        $policy = $service->updatePolicy($commission, $request->all());

        return response()->json([
            'success' => true,
            'data' => CommissionPolicyResource::make($policy)->toArray($request),
        ]);
    }

    public function deactivatePolicy(Request $request, CommissionPolicy $commission, CommissionService $service): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $commission->company_id, 'commissions.manage')) {
            return $response;
        }

        $policy = $service->deactivatePolicy($commission);

        return response()->json([
            'success' => true,
            'data' => CommissionPolicyResource::make($policy)->toArray($request),
        ]);
    }

    private function ensureCommerceAccess(Request $request, int $companyId, string $permission): ?JsonResponse
    {
        if (! $this->adminAccessService->allowsCommerceOperatorAccess($request->user(), $companyId, $permission)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return null;
    }
}

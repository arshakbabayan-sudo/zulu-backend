<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\SettlementResource;
use App\Http\Resources\Api\SupplierEntitlementResource;
use App\Models\Company;
use App\Models\Settlement;
use App\Services\Admin\AdminAccessService;
use App\Services\Finance\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function companySummary(Request $request, FinanceService $service): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $companyId = (int) $validated['company_id'];

        if ($response = $this->ensureFinanceCompanyAccess($request, $companyId, 'finance.entitlements.view')) {
            return $response;
        }

        $company = Company::query()->findOrFail($companyId);
        $summary = $service->getCompanyFinanceSummary($company);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    public function entitlements(Request $request, FinanceService $service): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'status' => ['sometimes', 'nullable', 'string'],
            'package_order_id' => ['sometimes', 'nullable', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = (int) $validated['company_id'];

        if ($response = $this->ensureFinanceCompanyAccess($request, $companyId, 'finance.entitlements.view')) {
            return $response;
        }

        $company = Company::query()->findOrFail($companyId);
        $filters = array_filter([
            'status' => $validated['status'] ?? null,
            'package_order_id' => $validated['package_order_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : $this->commerceListPerPage($request);
        $paginator = $service->listEntitlementsForCompany($company, $filters, $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, SupplierEntitlementResource::class);
    }

    public function markPayable(Request $request, FinanceService $service): JsonResponse
    {
        if (! $this->adminAccessService->isSuperAdmin($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'entitlement_ids' => ['required', 'array', 'min:1'],
            'entitlement_ids.*' => ['integer'],
        ]);

        $company = Company::query()->findOrFail((int) $validated['company_id']);
        $ids = array_map('intval', $validated['entitlement_ids']);
        $updated = $service->markEntitlementsPayable($ids, $company);

        return response()->json([
            'success' => true,
            'data' => [
                'updated_count' => $updated,
            ],
        ]);
    }

    public function settlements(Request $request, FinanceService $service): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = (int) $validated['company_id'];

        if ($response = $this->ensureFinanceCompanyAccess($request, $companyId, 'finance.settlements.view')) {
            return $response;
        }

        $company = Company::query()->findOrFail($companyId);
        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : $this->commerceListPerPage($request);
        $paginator = $service->listSettlementsForCompany($company, $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, SettlementResource::class);
    }

    public function createSettlement(Request $request, FinanceService $service): JsonResponse
    {
        if (! $this->adminAccessService->isSuperAdmin($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'entitlement_ids' => ['required', 'array', 'min:1'],
            'entitlement_ids.*' => ['integer'],
            'currency' => ['required', 'string', 'size:3'],
            'period_label' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string'],
        ]);

        $company = Company::query()->findOrFail((int) $validated['company_id']);
        $ids = array_map('intval', $validated['entitlement_ids']);

        $settlement = $service->createSettlement(
            $company,
            $request->user(),
            $ids,
            [
                'currency' => $validated['currency'],
                'period_label' => $validated['period_label'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => SettlementResource::make($settlement)->toArray($request),
        ], 201);
    }

    public function updateSettlementStatus(Request $request, Settlement $settlement, FinanceService $service): JsonResponse
    {
        if (! $this->adminAccessService->isSuperAdmin($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(Settlement::STATUSES)],
            'notes' => ['nullable', 'string'],
        ]);

        $updated = $service->updateSettlementStatus(
            $settlement,
            $validated['status'],
            $validated['notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => SettlementResource::make($updated)->toArray($request),
        ]);
    }

    private function ensureFinanceCompanyAccess(Request $request, int $companyId, string $permission): ?JsonResponse
    {
        if (! $this->adminAccessService->allowsCommerceOperatorAccess($request->user(), $companyId, $permission)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return null;
    }

    /**
     * Finance group v2 — Transactions page Export button.
     *
     * Streams a CSV combining entitlements (status='pending'|'payable') and
     * settlements for the given company. Each row carries a `kind` column so
     * downstream tooling can split them again. Bounded to 10 000 rows.
     *
     * GET /api/finance/export-csv?company_id={id}&kind={entitlements|settlements|all}
     */
    public function exportCsv(Request $request, FinanceService $service): StreamedResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'kind' => ['nullable', 'string', Rule::in(['entitlements', 'settlements', 'all'])],
        ]);
        $companyId = (int) $validated['company_id'];
        $kind = (string) ($validated['kind'] ?? 'all');

        if ($deny = $this->ensureAccess($request, $companyId, 'finance.view')) {
            abort($deny->status(), $deny->getData(true)['message'] ?? 'Forbidden');
        }

        $filename = "finance-{$kind}-{$companyId}-".now()->format('Y-m-d-His').'.csv';

        $company = Company::findOrFail($companyId);

        return response()->streamDownload(function () use ($service, $company, $kind): void {
            $fh = fopen('php://output', 'w');
            fwrite($fh, "\xEF\xBB\xBF"); // Excel-friendly UTF-8 BOM
            fputcsv($fh, ['kind', 'id', 'amount', 'currency', 'status', 'company_id', 'date', 'notes']);

            if ($kind === 'entitlements' || $kind === 'all') {
                $entitlements = $service->listEntitlementsForCompany($company, [], 10000)->getCollection();
                foreach ($entitlements as $e) {
                    fputcsv($fh, [
                        'entitlement',
                        $e->id,
                        (float) $e->net_amount,
                        $e->currency,
                        $e->status,
                        $e->company_id,
                        $e->payable_at,
                        $e->notes ?? '',
                    ]);
                }
            }
            if ($kind === 'settlements' || $kind === 'all') {
                $settlements = $service->listSettlementsForCompany($company, 10000)->getCollection();
                foreach ($settlements as $s) {
                    fputcsv($fh, [
                        'settlement',
                        $s->id,
                        (float) $s->total_net_amount,
                        $s->currency,
                        $s->status,
                        $s->company_id,
                        $s->settled_at,
                        $s->notes ?? '',
                    ]);
                }
            }

            fclose($fh);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}

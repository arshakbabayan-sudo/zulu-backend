<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\TransferDetailResource;
use App\Http\Resources\Api\TransferListResource;
use App\Models\Offer;
use App\Services\Admin\AdminAccessService;
use App\Services\Transfers\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function index(Request $request, TransferService $transferService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'transfers.view');
        $filters = $transferService->listingFiltersFromRequest($request);

        if (! $request->filled('page')) {
            $transfers = $transferService->listForCompanies($companyIds, $filters);

            return response()->json([
                'success' => true,
                'data' => TransferListResource::collection($transfers)->resolve($request),
            ]);
        }

        $paginator = $transferService->paginateForCompanies(
            $companyIds,
            $filters,
            $this->commerceListPerPage($request)
        );

        return $this->paginatedCommerceResourceResponse($request, $paginator, TransferListResource::class);
    }

    public function show(Request $request, string $transfer, TransferService $transferService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'transfers.view');
        $model = $transferService->findForCompanyScope($transfer, $companyIds);
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => TransferDetailResource::make($model)->toArray($request),
        ]);
    }

    /**
     * Create transfer
     *
     * Attaches a transfer module to an existing offer of type `transfer`.
     * On success, `offers.price` = `base_price`.
     *
     * @group Transfers
     * @bodyParam offer_id int required ID of an existing offer with type=transfer. Example: 70
     * @bodyParam transfer_title string required Example: Airport to City Center
     * @bodyParam transfer_type string required One of: airport_transfer, city_transfer, intercity. Example: airport_transfer
     * @bodyParam origin_location_id int required Origin location id (region/city) from location tree. Example: 1201
     * @bodyParam pickup_point_type string required Example: airport
     * @bodyParam pickup_point_name string required Example: Zvartnots International Airport
     * @bodyParam destination_location_id int required Destination location id (region/city) from location tree. Example: 1202
     * @bodyParam dropoff_point_type string required Example: address
     * @bodyParam dropoff_point_name string required Example: 1 Tigranyan St, Yerevan
     * @bodyParam service_date string required Date. Example: 2026-06-01
     * @bodyParam pickup_time string required Format HH:MM:SS. Example: 10:30:00
     * @bodyParam vehicle_category string required Example: sedan
     * @bodyParam base_price numeric required Example: 35.00
     * @bodyParam status string required Example: draft
     */
    public function store(Request $request, TransferService $transferService): JsonResponse
    {
        $validated = $request->validate(
            array_merge($transferService->transferStoreValidationRules(), ['company_id' => ['prohibited']])
        );

        $offer = Offer::query()->findOrFail((int) $request->input('offer_id'));

        if ($response = $this->ensureCommerceAccess($request, (int) $offer->company_id, 'transfers.create')) {
            return $response;
        }

        $transfer = $transferService->create($validated);
        $transfer->load(['offer']);

        return response()->json([
            'success' => true,
            'data' => TransferDetailResource::make($transfer)->toArray($request),
        ], 201);
    }

    public function update(Request $request, string $transfer, TransferService $transferService): JsonResponse
    {
        $request->validate([
            'offer_id' => ['prohibited'],
            'company_id' => ['prohibited'],
        ]);

        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'transfers.update');
        $model = $transferService->findForCompanyScope($transfer, $companyIds);
        if ($model === null) {
            $candidate = $transferService->findByIdWithTransferOffer($transfer);
            if ($candidate !== null && $request->user()->belongsToCompany((int) $candidate->company_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden',
                ], 403);
            }

            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $model->company_id, 'transfers.update')) {
            return $response;
        }

        $model = $transferService->update($model, $request->all());
        $model->load(['offer']);

        return response()->json([
            'success' => true,
            'data' => TransferDetailResource::make($model)->toArray($request),
        ]);
    }

    public function destroy(Request $request, string $transfer, TransferService $transferService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'transfers.delete');
        $model = $transferService->findForCompanyScope($transfer, $companyIds);
        if ($model === null) {
            $candidate = $transferService->findByIdWithTransferOffer($transfer);
            if ($candidate !== null && $request->user()->belongsToCompany((int) $candidate->company_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden',
                ], 403);
            }

            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $model->company_id, 'transfers.delete')) {
            return $response;
        }

        $transferService->delete($model);

        return response()->json([
            'success' => true,
            'data' => null,
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

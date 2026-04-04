<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ExcursionResource;
use App\Models\Offer;
use App\Services\Admin\AdminAccessService;
use App\Services\Excursions\ExcursionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExcursionController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function index(Request $request, ExcursionService $excursionService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'excursions.view');
        $filters = $excursionService->listingFiltersFromRequest($request);
        $filters['appearance_context'] = 'admin';

        if (! $request->filled('page')) {
            $excursions = $excursionService->listForCompanies($companyIds, $filters);

            return response()->json([
                'success' => true,
                'data' => ExcursionResource::collection($excursions)->resolve($request),
            ]);
        }

        $paginator = $excursionService->paginateForCompanies(
            $companyIds,
            $filters,
            $this->commerceListPerPage($request)
        );

        return $this->paginatedCommerceResourceResponse($request, $paginator, ExcursionResource::class);
    }

    public function show(Request $request, string $excursion, ExcursionService $excursionService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'excursions.view');
        $model = $excursionService->findForCompanyScope($excursion, $companyIds);
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => ExcursionResource::make($model)->toArray($request),
        ]);
    }

    /**
     * Create excursion
     *
     * Attaches an excursion module to an existing offer of type `excursion`.
     * On success, `offers.price` = `base_price`.
     *
     * @group Excursions
     * @bodyParam offer_id int required ID of an existing offer with type=excursion. Example: 90
     * @bodyParam location string required Example: Garni, Armenia
     * @bodyParam duration string required Example: 4 hours
     * @bodyParam group_size int required Minimum 1. Example: 10
     * @bodyParam base_price numeric required Example: 25.00
     * @bodyParam status string required Example: draft
     */
    public function store(Request $request, ExcursionService $excursionService): JsonResponse
    {
        $request->validate($excursionService->excursionStoreValidationRules());

        $offer = Offer::query()->findOrFail((int) $request->input('offer_id'));

        if ($response = $this->ensureCommerceAccess($request, (int) $offer->company_id, 'excursions.create')) {
            return $response;
        }

        $excursion = $excursionService->create($request->all());
        $excursion->load(['offer']);

        return response()->json([
            'success' => true,
            'data' => ExcursionResource::make($excursion)->toArray($request),
        ], 201);
    }

    public function update(Request $request, string $excursion, ExcursionService $excursionService): JsonResponse
    {
        $request->validate($excursionService->excursionUpdateValidationRules());

        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'excursions.update');
        $model = $excursionService->findForCompanyScope($excursion, $companyIds);
        if ($model === null) {
            $candidate = $excursionService->findByIdWithExcursionOffer($excursion);
            if ($candidate !== null && $candidate->offer && $request->user()->belongsToCompany((int) $candidate->offer->company_id)) {
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

        $model->loadMissing('offer');

        if ($response = $this->ensureCommerceAccess($request, (int) $model->offer->company_id, 'excursions.update')) {
            return $response;
        }

        $model = $excursionService->update($model, $request->all());
        $model->load(['offer']);

        return response()->json([
            'success' => true,
            'data' => ExcursionResource::make($model)->toArray($request),
        ]);
    }

    public function destroy(Request $request, string $excursion, ExcursionService $excursionService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'excursions.delete');
        $model = $excursionService->findForCompanyScope($excursion, $companyIds);
        if ($model === null) {
            $candidate = $excursionService->findByIdWithExcursionOffer($excursion);
            if ($candidate !== null && $candidate->offer && $request->user()->belongsToCompany((int) $candidate->offer->company_id)) {
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

        $model->loadMissing('offer');

        if ($response = $this->ensureCommerceAccess($request, (int) $model->offer->company_id, 'excursions.delete')) {
            return $response;
        }

        $excursionService->delete($model);

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

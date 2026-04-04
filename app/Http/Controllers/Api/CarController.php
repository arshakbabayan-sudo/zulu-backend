<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CarResource;
use App\Models\Offer;
use App\Services\Admin\AdminAccessService;
use App\Services\Cars\CarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function index(Request $request, CarService $carService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'cars.view');
        $filters = $carService->listingFiltersFromRequest($request);
        $filters['appearance_context'] = 'admin';

        if (! $request->filled('page')) {
            $cars = $carService->listForCompanies($companyIds, $filters);

            return response()->json([
                'success' => true,
                'data' => CarResource::collection($cars)->resolve($request),
            ]);
        }

        $paginator = $carService->paginateForCompanies(
            $companyIds,
            $filters,
            $this->commerceListPerPage($request)
        );

        return $this->paginatedCommerceResourceResponse($request, $paginator, CarResource::class);
    }

    public function show(Request $request, string $car, CarService $carService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'cars.view');
        $model = $carService->findForCompanyScope($car, $companyIds);
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => CarResource::make($model)->toArray($request),
        ]);
    }

    /**
     * Create car
     *
     * Attaches a car module to an existing offer of type `car`.
     * On success, `offers.price` = `base_price`.
     *
     * @group Cars
     * @bodyParam offer_id int required ID of an existing offer with type=car. Example: 80
     * @bodyParam pickup_location string required Example: Yerevan Airport
     * @bodyParam dropoff_location string required Example: Yerevan City Center
     * @bodyParam vehicle_class string required Example: economy
     * @bodyParam base_price numeric required Example: 45.00
     * @bodyParam status string required Example: draft
     */
    public function store(Request $request, CarService $carService): JsonResponse
    {
        $request->validate($carService->carStoreValidationRules());

        $offer = Offer::query()->findOrFail((int) $request->input('offer_id'));

        if ($response = $this->ensureCommerceAccess($request, (int) $offer->company_id, 'cars.create')) {
            return $response;
        }

        $car = $carService->create($request->all());
        $car->load(['offer']);

        return response()->json([
            'success' => true,
            'data' => CarResource::make($car)->toArray($request),
        ], 201);
    }

    public function update(Request $request, string $car, CarService $carService): JsonResponse
    {
        $request->validate($carService->carUpdateValidationRules());

        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'cars.update');
        $model = $carService->findForCompanyScope($car, $companyIds);
        if ($model === null) {
            $candidate = $carService->findByIdWithCarOffer($car);
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

        if ($response = $this->ensureCommerceAccess($request, (int) $model->offer->company_id, 'cars.update')) {
            return $response;
        }

        $model = $carService->update($model, $request->all());
        $model->load(['offer']);

        return response()->json([
            'success' => true,
            'data' => CarResource::make($model)->toArray($request),
        ]);
    }

    public function destroy(Request $request, string $car, CarService $carService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'cars.delete');
        $model = $carService->findForCompanyScope($car, $companyIds);
        if ($model === null) {
            $candidate = $carService->findByIdWithCarOffer($car);
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

        if ($response = $this->ensureCommerceAccess($request, (int) $model->offer->company_id, 'cars.delete')) {
            return $response;
        }

        $carService->delete($model);

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

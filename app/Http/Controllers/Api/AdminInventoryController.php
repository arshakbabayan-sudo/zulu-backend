<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CarResource;
use App\Http\Resources\Api\ExcursionResource;
use App\Http\Resources\Api\FlightResource;
use App\Http\Resources\Api\HotelListResource;
use App\Http\Resources\Api\TransferListResource;
use App\Services\Admin\AdminAccessService;
use App\Services\Cars\CarService;
use App\Services\Excursions\ExcursionService;
use App\Services\Flights\FlightService;
use App\Services\Hotels\HotelService;
use App\Services\Transfers\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminInventoryController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function flights(Request $request, FlightService $flightService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'flights.view');
        $filters = $flightService->listingFiltersFromRequest($request);
        $paginator = $flightService->paginateForCompanies($companyIds, $filters, $this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => FlightResource::collection($paginator->items())->resolve($request),
            'meta' => $this->meta($paginator),
        ]);
    }

    public function hotels(Request $request, HotelService $hotelService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'hotels.view');
        $filters = $hotelService->listingFiltersFromRequest($request);
        $paginator = $hotelService->paginateForCompanies($companyIds, $filters, $this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => HotelListResource::collection($paginator->items())->resolve($request),
            'meta' => $this->meta($paginator),
        ]);
    }

    public function transfers(Request $request, TransferService $transferService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'transfers.view');
        $filters = $transferService->listingFiltersFromRequest($request);
        $paginator = $transferService->paginateForCompanies($companyIds, $filters, $this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => TransferListResource::collection($paginator->items())->resolve($request),
            'meta' => $this->meta($paginator),
        ]);
    }

    public function cars(Request $request, CarService $carService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'cars.view');
        $filters = $carService->listingFiltersFromRequest($request);
        $paginator = $carService->paginateForCompanies($companyIds, $filters, $this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => CarResource::collection($paginator->items())->resolve($request),
            'meta' => $this->meta($paginator),
        ]);
    }

    public function excursions(Request $request, ExcursionService $excursionService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'excursions.view');
        $filters = $excursionService->listingFiltersFromRequest($request);
        $paginator = $excursionService->paginateForCompanies($companyIds, $filters, $this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => ExcursionResource::collection($paginator->items())->resolve($request),
            'meta' => $this->meta($paginator),
        ]);
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 20);

        return max(5, min($perPage, 100));
    }

    /**
     * @return array<string, int>
     */
    private function meta(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}

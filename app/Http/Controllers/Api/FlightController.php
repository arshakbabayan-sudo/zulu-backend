<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\FlightResource;
use App\Models\Company;
use App\Models\Flight;
use App\Models\FlightCabin;
use App\Models\Offer;
use App\Services\Admin\AdminAccessService;
use App\Services\Flights\FlightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlightController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function index(Request $request, FlightService $flightService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'flights.view');
        $filters = $flightService->listingFiltersFromRequest($request);

        if (! $request->filled('page')) {
            $flights = $flightService->listForCompanies($companyIds, $filters);

            return response()->json([
                'success' => true,
                'data' => FlightResource::collection($flights)->resolve($request),
            ]);
        }

        $paginator = $flightService->paginateForCompanies(
            $companyIds,
            $filters,
            $this->commerceListPerPage($request)
        );

        return $this->paginatedCommerceResourceResponse($request, $paginator, FlightResource::class);
    }

    public function show(Request $request, Flight $flight): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $flight->company_id, 'flights.view')) {
            return $response;
        }

        $flight->loadMissing(['offer', 'company', 'cabins']);

        return response()->json([
            'success' => true,
            'data' => FlightResource::make($flight)->toArray($request),
        ]);
    }

    public function store(Request $request, FlightService $flightService): JsonResponse
    {
        $validated = $request->validate([
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
        ]);

        $offer = Offer::query()->findOrFail((int) $validated['offer_id']);

        if ($response = $this->ensureCommerceAccess($request, (int) $offer->company_id, 'flights.create')) {
            return $response;
        }

        $geoService = app(\App\Services\Infrastructure\GeoRestrictionService::class);
        $company = Company::find($validated['company_id'] ?? $offer->company_id);
        if ($company) {
            $departureCountry = $request->input('departure_country', '');
            $error = $geoService->validateServiceCountry($company, $departureCountry, 'flight');
            if ($error !== null) {
                return response()->json([
                    'success' => false,
                    'message' => $error,
                ], 422);
            }
        }

        $flight = $flightService->create($request->all());
        $flight->loadMissing(['offer', 'company', 'cabins']);

        return response()->json([
            'success' => true,
            'data' => FlightResource::make($flight)->toArray($request),
        ], 201);
    }

    public function update(Request $request, Flight $flight, FlightService $flightService): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $flight->company_id, 'flights.update')) {
            return $response;
        }

        $flight = $flightService->update($flight, $request->all());
        $flight->loadMissing(['offer', 'company', 'cabins']);

        return response()->json([
            'success' => true,
            'data' => FlightResource::make($flight)->toArray($request),
        ]);
    }

    public function destroy(Request $request, Flight $flight, FlightService $flightService): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $flight->company_id, 'flights.delete')) {
            return $response;
        }

        $flightService->delete($flight);

        return response()->json([
            'success' => true,
            'data' => null,
        ]);
    }

    public function listCabins(Request $request, Flight $flight, FlightService $flightService): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $flight->company_id, 'flights.view')) {
            return $response;
        }

        $cabins = $flightService->listCabins($flight)->map(fn (FlightCabin $c) => $c->toApiArray())->values()->all();

        return response()->json([
            'success' => true,
            'data' => $cabins,
        ]);
    }

    public function addCabin(Request $request, Flight $flight, FlightService $flightService): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $flight->company_id, 'flights.update')) {
            return $response;
        }

        $cabin = $flightService->addCabin($flight, $request->all());

        return response()->json([
            'success' => true,
            'data' => $cabin->toApiArray(),
        ], 201);
    }

    public function updateCabin(Request $request, Flight $flight, FlightCabin $cabin, FlightService $flightService): JsonResponse
    {
        if ((int) $cabin->flight_id !== (int) $flight->id) {
            abort(404);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $flight->company_id, 'flights.update')) {
            return $response;
        }

        $cabin = $flightService->updateCabin($cabin, $request->all());

        return response()->json([
            'success' => true,
            'data' => $cabin->toApiArray(),
        ]);
    }

    public function deleteCabin(Request $request, Flight $flight, FlightCabin $cabin, FlightService $flightService): JsonResponse
    {
        if ((int) $cabin->flight_id !== (int) $flight->id) {
            abort(404);
        }

        if ($response = $this->ensureCommerceAccess($request, (int) $flight->company_id, 'flights.delete')) {
            return $response;
        }

        $flightService->deleteCabin($cabin);

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

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
use App\Services\Infrastructure\GeoRestrictionService;
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
        $filters['appearance_context'] = 'admin';

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

    /**
     * Create flight
     *
     * Attaches a flight module to an existing offer of type `flight`.
     * On success, `offers.price` is set to `adult_price` (or MIN cabin price if cabins exist).
     * Returns 422 if the offer already has a flight, or if the departure country is geo-restricted.
     *
     * @group Flights
     * @bodyParam offer_id int required ID of an existing offer with type=flight. Example: 42
     * @bodyParam departure_country string required Example: Armenia
     * @bodyParam departure_city string required Example: Yerevan
     * @bodyParam departure_airport string required Example: Zvartnots International
     * @bodyParam arrival_country string required Example: UAE
     * @bodyParam arrival_city string required Example: Dubai
     * @bodyParam arrival_airport string required Example: Dubai International
     * @bodyParam departure_at string required ISO datetime. Example: 2026-06-01T08:00:00
     * @bodyParam arrival_at string required ISO datetime, must be after departure_at. Example: 2026-06-01T12:00:00
     * @bodyParam adult_price numeric required Must be > 0. Example: 180.00
     * @bodyParam status string required One of: draft, active, inactive, sold_out, cancelled, completed, archived. Example: draft
     */
    public function store(Request $request, FlightService $flightService): JsonResponse
    {
        $validated = $request->validate(
            array_merge($flightService->flightStoreValidationRules(), ['company_id' => ['prohibited']])
        );

        $offer = Offer::query()->findOrFail((int) $request->input('offer_id'));

        if ($response = $this->ensureCommerceAccess($request, (int) $offer->company_id, 'flights.create')) {
            return $response;
        }

        $geoService = app(GeoRestrictionService::class);
        $company = Company::find($offer->company_id);
        if ($company) {
            $departureCountry = $validated['departure_country'] ?? '';
            $error = $geoService->validateServiceCountry($company, $departureCountry, 'flight');
            if ($error !== null) {
                return response()->json([
                    'success' => false,
                    'message' => $error,
                ], 422);
            }
        }

        $flight = $flightService->create($validated);
        $flight->loadMissing(['offer', 'company', 'cabins']);

        return response()->json([
            'success' => true,
            'data' => FlightResource::make($flight)->toArray($request),
        ], 201);
    }

    public function update(Request $request, Flight $flight, FlightService $flightService): JsonResponse
    {
        $request->validate([
            'offer_id'   => ['prohibited'],
            'company_id' => ['prohibited'],
        ]);

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

    /**
     * Add cabin to flight
     *
     * Adds a cabin class row to the flight. After adding, `offers.price` is recalculated
     * as MIN(`adult_price`) across all cabins.
     * Returns 422 if the cabin class already exists on this flight.
     *
     * @group Flight Cabins
     * @bodyParam cabin_class string required One of: economy, premium_economy, business, first. Example: economy
     * @bodyParam adult_price numeric required Must be > 0. Example: 180.00
     * @bodyParam seat_capacity_total int required Example: 150
     * @bodyParam seat_capacity_available int required Example: 120
     */
    public function addCabin(Request $request, Flight $flight, FlightService $flightService): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $flight->company_id, 'flights.update')) {
            return $response;
        }

        $validated = $request->validate($flightService->cabinStoreValidationRules());

        $cabin = $flightService->addCabin($flight, $validated);

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

        $request->validate(['cabin_class' => ['prohibited'], 'flight_id' => ['prohibited']]);

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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\HotelDetailResource;
use App\Http\Resources\Api\HotelListResource;
use App\Models\Company;
use App\Models\Offer;
use App\Services\Admin\AdminAccessService;
use App\Services\Hotels\HotelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HotelController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function index(Request $request, HotelService $hotelService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'hotels.view');
        $filters = $hotelService->listingFiltersFromRequest($request);

        if (! $request->filled('page')) {
            $hotels = $hotelService->listForCompanies($companyIds, $filters);

            return response()->json([
                'success' => true,
                'data' => HotelListResource::collection($hotels)->resolve($request),
            ]);
        }

        $paginator = $hotelService->paginateForCompanies(
            $companyIds,
            $filters,
            $this->commerceListPerPage($request)
        );

        return $this->paginatedCommerceResourceResponse($request, $paginator, HotelListResource::class);
    }

    public function show(Request $request, string $hotel, HotelService $hotelService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'hotels.view');
        $model = $hotelService->findForCompanyScope($hotel, $companyIds);
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => HotelDetailResource::make($model)->toArray($request),
        ]);
    }

    public function store(Request $request, HotelService $hotelService): JsonResponse
    {
        $request->validate([
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'company_id' => ['prohibited'],
        ]);

        $offer = Offer::query()->findOrFail((int) $request->input('offer_id'));

        if ($response = $this->ensureCommerceAccess($request, (int) $offer->company_id, 'hotels.create')) {
            return $response;
        }

        $company = Company::find($offer->company_id);
        $hotelCountry = $request->input('country', '');
        $error = app(\App\Services\Infrastructure\GeoRestrictionService::class)->validateServiceCountry($company, $hotelCountry, 'hotel');
        if ($error !== null) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        $hotel = $hotelService->create($request->all());
        $hotel->load(['offer', 'rooms.pricings']);

        return response()->json([
            'success' => true,
            'data' => HotelDetailResource::make($hotel)->toArray($request),
        ], 201);
    }

    public function update(Request $request, string $hotel, HotelService $hotelService): JsonResponse
    {
        $request->validate([
            'offer_id' => ['prohibited'],
            'company_id' => ['prohibited'],
        ]);

        if ($request->exists('rooms')) {
            throw ValidationException::withMessages([
                'rooms' => ['Room updates are not supported.'],
            ]);
        }

        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'hotels.update');
        $model = $hotelService->findForCompanyScope($hotel, $companyIds);
        if ($model === null) {
            $candidate = $hotelService->findByIdWithHotelOffer($hotel);
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

        if ($response = $this->ensureCommerceAccess($request, (int) $model->company_id, 'hotels.update')) {
            return $response;
        }

        $model = $hotelService->update($model, $request->all());
        $model->load(['offer', 'rooms.pricings']);

        return response()->json([
            'success' => true,
            'data' => HotelDetailResource::make($model)->toArray($request),
        ]);
    }

    public function destroy(Request $request, string $hotel, HotelService $hotelService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'hotels.delete');
        $model = $hotelService->findForCompanyScope($hotel, $companyIds);
        if ($model === null) {
            $candidate = $hotelService->findByIdWithHotelOffer($hotel);
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

        if ($response = $this->ensureCommerceAccess($request, (int) $model->company_id, 'hotels.delete')) {
            return $response;
        }

        $hotelService->delete($model);

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

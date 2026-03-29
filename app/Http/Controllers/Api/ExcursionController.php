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

    public function store(Request $request, ExcursionService $excursionService): JsonResponse
    {
        $request->validate([
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'location' => ['required', 'string', 'max:255'],
            'duration' => ['required', 'string', 'max:255'],
            'group_size' => ['required', 'integer', 'min:1'],
        ]);

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
        $request->validate([
            'offer_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'location' => ['sometimes', 'string', 'max:255'],
            'duration' => ['sometimes', 'string', 'max:255'],
            'group_size' => ['sometimes', 'integer', 'min:1'],
        ]);

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

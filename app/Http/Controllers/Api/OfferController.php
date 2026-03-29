<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\OfferResource;
use App\Models\Offer;
use App\Services\Admin\AdminAccessService;
use App\Services\Offers\OfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OfferController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function index(Request $request, OfferService $offerService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'offers.view');

        if (! $request->filled('page')) {
            $offers = $offerService->listForCompanies($companyIds);

            return response()->json([
                'success' => true,
                'data' => OfferResource::collection($offers)->resolve(),
            ]);
        }

        $paginator = $offerService->paginateForCompanies($companyIds, $this->commerceListPerPage($request));

        return $this->paginatedCommerceResourceResponse($request, $paginator, OfferResource::class);
    }

    public function show(Request $request, Offer $offer): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $offer->company_id, 'offers.view')) {
            return $response;
        }

        $this->eagerLoadModuleRelation($offer);

        return response()->json([
            'success' => true,
            'data' => OfferResource::make($offer)->toArray($request),
        ]);
    }

    private function eagerLoadModuleRelation(Offer $offer): void
    {
        $relation = match ($offer->type) {
            'flight' => 'flight',
            'hotel' => 'hotel',
            'transfer' => 'transfer',
            'car' => 'car',
            'excursion' => 'excursion',
            'package' => 'package',
            'visa' => 'visa',
            default => null,
        };

        if ($relation !== null) {
            $offer->loadMissing($relation === 'flight' ? 'flight.cabins' : $relation);
        }
    }

    public function store(Request $request, OfferService $offerService): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'type' => ['required', 'string', Rule::in(Offer::ALLOWED_TYPES)],
            'title' => ['required', 'string'],
            'price' => ['required', 'numeric'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        $companyId = (int) $validated['company_id'];
        if ($response = $this->ensureCommerceAccess($request, $companyId, 'offers.create')) {
            return $response;
        }

        $offer = $offerService->create([
            'company_id' => $companyId,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'price' => $validated['price'],
            'currency' => $validated['currency'],
        ]);

        return response()->json([
            'success' => true,
            'data' => OfferResource::make($offer)->toArray($request),
        ]);
    }

    public function publish(Request $request, OfferService $offerService, Offer $offer): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $offer->company_id, 'offers.publish')) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => OfferResource::make($offerService->publish($offer))->toArray($request),
        ]);
    }

    public function archive(Request $request, OfferService $offerService, Offer $offer): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $offer->company_id, 'offers.archive')) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => OfferResource::make($offerService->archive($offer))->toArray($request),
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

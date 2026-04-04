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

    /**
     * List offers
     *
     * Returns all offers for the authenticated operator's companies.
     * Supports optional `?type=` filter (flight|hotel|transfer|car|excursion|package|visa)
     * and `?page=` for pagination.
     *
     * @group Offers
     * @queryParam type string Filter by offer type. Example: flight
     * @queryParam page int Page number for paginated results.
     */
    public function index(Request $request, OfferService $offerService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'offers.view');
        $type = null;
        if ($request->filled('type')) {
            $candidate = (string) $request->query('type');
            if (in_array($candidate, Offer::ALLOWED_TYPES, true)) {
                $type = $candidate;
            }
        }

        if (! $request->filled('page')) {
            $offers = $offerService->listForCompanies($companyIds, $type);

            return response()->json([
                'success' => true,
                'data' => OfferResource::collection($offers)->resolve(),
            ]);
        }

        $paginator = $offerService->paginateForCompanies($companyIds, $this->commerceListPerPage($request), $type);

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

    /**
     * Create offer
     *
     * Creates a new offer shell. After creation, attach a module via
     * POST /api/flights, /api/hotels, etc. using the returned `id` as `offer_id`.
     * `offers.price` will be overwritten by the module service on first write.
     *
     * @group Offers
     * @bodyParam company_id int required The company that owns this offer. Example: 1
     * @bodyParam type string required One of: flight, hotel, transfer, car, excursion, package, visa. Example: flight
     * @bodyParam title string required Display title. Example: Yerevan → Dubai Economy
     * @bodyParam price numeric required Initial B2B anchor price (overwritten by module service). Example: 150.00
     * @bodyParam currency string required ISO 4217, 3 chars. Example: USD
     */
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

    /**
     * Publish offer
     *
     * Sets offer status to `published` and cascades module status from `draft` → `active`.
     *
     * @group Offers
     */
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

    /**
     * Archive offer
     *
     * Sets offer status to `archived` and cascades module status to `archived`.
     *
     * @group Offers
     */
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

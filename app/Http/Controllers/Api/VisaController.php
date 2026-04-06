<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\VisaResource;
use App\Models\Offer;
use App\Models\Visa;
use App\Services\Admin\AdminAccessService;
use App\Services\Visas\VisaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VisaController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function index(Request $request, VisaService $visaService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'visas.view');

        if (! $request->filled('page')) {
            $visas = $visaService->listForCompanies($companyIds);

            return response()->json([
                'success' => true,
                'data' => VisaResource::collection($visas)->resolve(),
            ]);
        }

        $paginator = $visaService->paginateForCompanies($companyIds, $this->commerceListPerPage($request));

        return $this->paginatedCommerceResourceResponse($request, $paginator, VisaResource::class);
    }

    public function show(Request $request, Visa $visa): JsonResponse
    {
        $visa->loadMissing('offer');
        $offer = $visa->offer;
        if ($response = $this->ensureCommerceAccess($request, (int) $offer->company_id, 'visas.view')) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => VisaResource::make($visa)->toArray($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'offer_id' => [
                'required',
                'integer',
                Rule::exists('offers', 'id')->where('type', 'visa'),
                Rule::unique('visas', 'offer_id'),
            ],
            'country' => ['required', 'string', 'max:255'],
            'visa_type' => ['required', 'string', 'max:255'],
            'processing_days' => ['nullable', 'integer', 'min:0'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'required_documents' => VisaService::storeRequiredDocumentsRules(),
            'price' => ['nullable', 'numeric', 'min:0'],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
        ]);

        $offer = Offer::query()->findOrFail((int) $validated['offer_id']);

        if ($response = $this->ensureCommerceAccess($request, (int) $offer->company_id, 'visas.create')) {
            return $response;
        }

        $visa = Visa::query()->create([
            'offer_id' => (int) $validated['offer_id'],
            'country' => $validated['country'],
            'visa_type' => $validated['visa_type'],
            'processing_days' => $validated['processing_days'] ?? null,
            'name' => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'required_documents' => VisaService::normalizeRequiredDocuments($validated['required_documents'] ?? null),
            'price' => $validated['price'] ?? null,
            'country_id' => $validated['country_id'] ?? null,
        ]);

        $visa->load('offer');

        return response()->json([
            'success' => true,
            'data' => VisaResource::make($visa)->toArray($request),
        ], 201);
    }

    public function update(Request $request, VisaService $visaService, Visa $visa): JsonResponse
    {
        $offer = $visa->offer;
        if ($response = $this->ensureCommerceAccess($request, (int) $offer->company_id, 'visas.update')) {
            return $response;
        }

        $visa = $visaService->update($visa, $request->all());
        $visa->loadMissing('offer');

        return response()->json([
            'success' => true,
            'data' => VisaResource::make($visa)->toArray($request),
        ]);
    }

    public function destroy(Request $request, VisaService $visaService, Visa $visa): JsonResponse
    {
        $offer = $visa->offer;
        if ($response = $this->ensureCommerceAccess($request, (int) $offer->company_id, 'visas.delete')) {
            return $response;
        }

        $visaService->delete($visa);

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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PackageResource;
use App\Models\Offer;
use App\Models\PackageComponent;
use App\Services\Admin\AdminAccessService;
use App\Services\Packages\PackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function index(Request $request, PackageService $packageService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'packages.view');

        if (! $request->filled('page')) {
            $packages = $packageService->listForCompanies($companyIds);

            return response()->json([
                'success' => true,
                'data' => PackageResource::collection($packages)->resolve($request),
            ]);
        }

        $paginator = $packageService->paginateForCompanies($companyIds, $this->commerceListPerPage($request));

        return $this->paginatedCommerceResourceResponse($request, $paginator, PackageResource::class);
    }

    public function store(Request $request, PackageService $packageService): JsonResponse
    {
        $request->validate([
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
            'package_type' => ['required', 'string', 'max:32'],
        ]);

        $offer = Offer::query()->findOrFail((int) $request->input('offer_id'));

        if ($response = $this->ensureCommerceAccess($request, (int) $offer->company_id, 'packages.create')) {
            return $response;
        }

        $package = $packageService->create($request->all());
        $package->load(['offer', 'components.offer']);

        return response()->json([
            'success' => true,
            'data' => PackageResource::make($package)->toArray($request),
        ], 201);
    }

    public function update(Request $request, string $package, PackageService $packageService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'packages.edit');
        $model = $packageService->findForCompanyScope($package, $companyIds);
        if ($model === null) {
            $candidate = $packageService->findByIdWithPackageOffer($package);
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

        if ($response = $this->ensureCommerceAccess($request, (int) $model->company_id, 'packages.edit')) {
            return $response;
        }

        $model = $packageService->update($model, $request->all());
        $model->load(['offer', 'components.offer']);

        return response()->json([
            'success' => true,
            'data' => PackageResource::make($model)->toArray($request),
        ]);
    }

    public function destroy(Request $request, string $package, PackageService $packageService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'packages.delete');
        $model = $packageService->findForCompanyScope($package, $companyIds);
        if ($model === null) {
            $candidate = $packageService->findByIdWithPackageOffer($package);
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

        if ($response = $this->ensureCommerceAccess($request, (int) $model->company_id, 'packages.delete')) {
            return $response;
        }

        $packageService->delete($model);

        return response()->json([
            'success' => true,
            'data' => null,
        ]);
    }

    public function addComponent(Request $request, string $package, PackageService $packageService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'packages.manage_components');
        $model = $packageService->findForCompanyScope($package, $companyIds);
        if ($model === null) {
            $candidate = $packageService->findByIdWithPackageOffer($package);
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

        if ($response = $this->ensureCommerceAccess($request, (int) $model->company_id, 'packages.manage_components')) {
            return $response;
        }

        $component = $packageService->addComponent($model, $request->all());
        $component->load('offer');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $component->id,
                'package_id' => $component->package_id,
                'offer_id' => $component->offer_id,
                'module_type' => $component->module_type,
                'package_role' => $component->package_role,
                'is_required' => (bool) $component->is_required,
                'sort_order' => (int) $component->sort_order,
                'selection_mode' => $component->selection_mode,
                'price_override' => $component->price_override !== null ? (float) $component->price_override : null,
                'offer' => $component->offer ? [
                    'id' => $component->offer->id,
                    'title' => $component->offer->title,
                    'price' => $component->offer->price !== null ? (float) $component->offer->price : null,
                    'currency' => $component->offer->currency,
                    'status' => $component->offer->status,
                ] : null,
            ],
        ], 201);
    }

    public function reorderComponents(Request $request, string $package, PackageService $packageService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'packages.manage_components');
        $model = $packageService->findForCompanyScope($package, $companyIds);
        if ($model === null) {
            $candidate = $packageService->findByIdWithPackageOffer($package);
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

        if ($response = $this->ensureCommerceAccess($request, (int) $model->company_id, 'packages.manage_components')) {
            return $response;
        }

        $validated = $request->validate([
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer', 'min:1'],
        ]);

        $packageService->reorderComponents($model, $validated['ordered_ids']);

        $model = $model->fresh(['components']);

        return response()->json([
            'success' => true,
            'data' => PackageResource::make($model)->toArray($request),
        ]);
    }

    public function removeComponent(Request $request, string $package, string $component, PackageService $packageService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'packages.manage_components');
        $model = $packageService->findForCompanyScope($package, $companyIds);
        if ($model === null) {
            $candidate = $packageService->findByIdWithPackageOffer($package);
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

        if ($response = $this->ensureCommerceAccess($request, (int) $model->company_id, 'packages.manage_components')) {
            return $response;
        }

        $row = PackageComponent::query()
            ->where('package_id', $model->id)
            ->whereKey($component)
            ->first();

        if ($row === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        $packageService->removeComponent($model, (int) $row->id);

        return response()->json([
            'success' => true,
            'data' => null,
        ]);
    }

    public function activate(Request $request, string $package, PackageService $packageService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'packages.edit');
        $model = $packageService->findForCompanyScope($package, $companyIds);
        if ($model === null) {
            $candidate = $packageService->findByIdWithPackageOffer($package);
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

        if ($response = $this->ensureCommerceAccess($request, (int) $model->company_id, 'packages.edit')) {
            return $response;
        }

        $model = $packageService->activate($model);
        $model->load(['offer', 'components.offer']);

        return response()->json([
            'success' => true,
            'data' => PackageResource::make($model)->toArray($request),
        ]);
    }

    public function deactivate(Request $request, string $package, PackageService $packageService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'packages.edit');
        $model = $packageService->findForCompanyScope($package, $companyIds);
        if ($model === null) {
            $candidate = $packageService->findByIdWithPackageOffer($package);
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

        if ($response = $this->ensureCommerceAccess($request, (int) $model->company_id, 'packages.edit')) {
            return $response;
        }

        $model = $packageService->deactivate($model);
        $model->load(['offer', 'components.offer']);

        return response()->json([
            'success' => true,
            'data' => PackageResource::make($model)->toArray($request),
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

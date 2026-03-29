<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PackageOrderResource;
use App\Models\Package;
use App\Models\PackageOrder;
use App\Services\Admin\AdminAccessService;
use App\Services\Packages\PackageOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PackageOrderController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function store(Request $request, PackageOrderService $service): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'adults_count' => ['sometimes', 'integer', 'min:1'],
            'children_count' => ['sometimes', 'integer', 'min:0'],
            'infants_count' => ['sometimes', 'integer', 'min:0'],
            'booking_channel' => ['sometimes', 'string', Rule::in(PackageOrder::BOOKING_CHANNELS)],
            'notes' => ['nullable', 'string'],
        ]);

        $package = Package::query()->findOrFail((int) $validated['package_id']);

        $input = [
            'adults_count' => $validated['adults_count'] ?? 1,
            'children_count' => $validated['children_count'] ?? 0,
            'infants_count' => $validated['infants_count'] ?? 0,
            'booking_channel' => $validated['booking_channel'] ?? 'public_b2c',
            'notes' => $validated['notes'] ?? null,
        ];

        $order = $service->createOrder($package, $request->user(), $input);

        return response()->json([
            'success' => true,
            'data' => PackageOrderResource::make($order)->toArray($request),
        ], 201);
    }

    public function index(Request $request, PackageOrderService $service): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));
        $paginator = $service->listForUser($request->user(), $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, PackageOrderResource::class);
    }

    public function show(Request $request, int $order, PackageOrderService $service): JsonResponse
    {
        $model = $service->findForUser($order, $request->user());
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => PackageOrderResource::make($model)->toArray($request),
        ]);
    }

    public function markPaid(Request $request, int $order, PackageOrderService $service): JsonResponse
    {
        $model = $service->findForUser($order, $request->user());
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        $updated = $service->markPaid($model);

        return response()->json([
            'success' => true,
            'data' => PackageOrderResource::make($updated)->toArray($request),
        ]);
    }

    public function companyIndex(Request $request, PackageOrderService $service): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'package_orders.view');
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));
        $paginator = $service->listForCompanies($companyIds, $perPage);

        return $this->paginatedCommerceResourceResponse($request, $paginator, PackageOrderResource::class);
    }

    public function companyShow(Request $request, int $order, PackageOrderService $service): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'package_orders.view');
        $model = $service->findForCompanyScope($order, $companyIds);
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => PackageOrderResource::make($model)->toArray($request),
        ]);
    }

    public function confirmItem(Request $request, int $order, int $item, PackageOrderService $service): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'package_orders.manage');
        $model = $service->findForCompanyScope($order, $companyIds);
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        $service->confirmItem($model, $item);
        $updated = $service->findForCompanyScope($order, $companyIds);

        return response()->json([
            'success' => true,
            'data' => PackageOrderResource::make($updated)->toArray($request),
        ]);
    }

    public function failItem(Request $request, int $order, int $item, PackageOrderService $service): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'package_orders.manage');
        $model = $service->findForCompanyScope($order, $companyIds);
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        $service->failItem($model, $item, $validated['reason']);
        $updated = $service->findForCompanyScope($order, $companyIds);

        return response()->json([
            'success' => true,
            'data' => PackageOrderResource::make($updated)->toArray($request),
        ]);
    }

    public function cancelOrder(Request $request, int $order, PackageOrderService $service): JsonResponse
    {
        $model = $service->findForUser($order, $request->user());
        if ($model === null) {
            $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'package_orders.manage');
            $model = $service->findForCompanyScope($order, $companyIds);
        }
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        $updated = $service->cancelOrder($model);

        return response()->json([
            'success' => true,
            'data' => PackageOrderResource::make($updated)->toArray($request),
        ]);
    }
}

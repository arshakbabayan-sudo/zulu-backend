<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InsurancePolicy;
use App\Models\InsuranceProduct;
use App\Services\Admin\AdminAccessService;
use App\Services\Insurance\InsurancePolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AdminInsuranceController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private InsurancePolicyService $policyService,
    ) {}

    private function denyUnlessPlatformAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    // === Products ===

    /** GET /api/platform-admin/insurance/products */
    public function indexProducts(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $user = $request->user();
        $products = InsuranceProduct::query()
            ->with('company:id,name')
            ->when($user !== null && ! $this->adminAccessService->isSuperAdmin($user), function ($q) use ($user) {
                // Tenant scope: non-super callers see only their own company's products.
                $q->whereIn('company_id', $this->adminAccessService->callerCompanyIds($user) ?: [0]);
            })
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $products]);
    }

    /** POST /api/platform-admin/insurance/products */
    public function storeProduct(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'underwriter_name' => ['required', 'string'],
            'underwriter_license_ref' => ['nullable', 'string'],
            'product_name' => ['required', 'string'],
            'coverage_territory' => ['nullable', Rule::in(InsuranceProduct::TERRITORIES)],
            'covered_countries' => ['nullable', 'array'],
            'excluded_countries' => ['nullable', 'array'],
            'coverage_details' => ['required', 'array'],
            'age_tiers' => ['required', 'array', 'min:1'],
            'age_tiers.*.min_age' => ['required', 'integer', 'min:0'],
            'age_tiers.*.max_age' => ['required', 'integer', 'gte:age_tiers.*.min_age'],
            'age_tiers.*.multiplier' => ['required', 'numeric', 'min:0'],
            'base_premium' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'duration_options' => ['required', 'array', 'min:1'],
            'pre_existing_covered' => ['nullable', 'boolean'],
            'sports_coverage' => ['nullable', 'array'],
            'exclusions' => ['nullable', 'array'],
            'policy_template_url' => ['nullable', 'string'],
        ]);

        $product = InsuranceProduct::query()->create($validated);

        return response()->json(['success' => true, 'data' => $product], 201);
    }

    /** PATCH /api/platform-admin/insurance/products/{id} */
    public function updateProduct(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $product = InsuranceProduct::query()->find($id);
        if ($product === null) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $validated = $request->validate([
            'product_name' => ['nullable', 'string'],
            'coverage_details' => ['nullable', 'array'],
            'age_tiers' => ['nullable', 'array'],
            'base_premium' => ['nullable', 'numeric', 'min:0'],
            'duration_options' => ['nullable', 'array'],
            'status' => ['nullable', Rule::in(InsuranceProduct::STATUSES)],
        ]);

        $product->fill(array_filter($validated, fn ($v) => $v !== null));
        $product->save();

        return response()->json(['success' => true, 'data' => $product->fresh()]);
    }

    // === Policies ===

    /** GET /api/platform-admin/insurance/policies */
    public function indexPolicies(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $query = InsurancePolicy::query()
            ->with(['product:id,product_name,underwriter_name', 'user:id,name,email']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 200);
        $paginated = $query->orderByDesc('issued_at')->paginate($perPage);

        return response()->json(['success' => true] + $paginated->toArray());
    }

    /** POST /api/platform-admin/insurance/policies/{id}/cancel */
    public function cancelPolicy(Request $request, string $id): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $policy = InsurancePolicy::query()->find($id);
        if ($policy === null) {
            return response()->json(['success' => false, 'message' => 'Policy not found'], 404);
        }

        $reason = (string) $request->input('reason', '');
        if (trim($reason) === '') {
            return response()->json(['success' => false, 'message' => 'Reason required'], 422);
        }

        try {
            $cancelled = $this->policyService->cancel($policy, 'ADMIN: '.$reason);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $cancelled]);
    }
}

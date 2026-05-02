<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InsurancePolicy;
use App\Models\InsuranceProduct;
use App\Services\Insurance\InsurancePolicyService;
use App\Services\Insurance\InsurancePricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CustomerInsuranceController extends Controller
{
    public function __construct(
        private InsurancePricingService $pricing,
        private InsurancePolicyService $policy,
    ) {}

    /** GET /api/customer/insurance/products */
    public function listProducts(Request $request): JsonResponse
    {
        $query = InsuranceProduct::query()
            ->where('status', 'active')
            ->orderBy('product_name');

        if ($territory = $request->query('territory')) {
            $query->where('coverage_territory', $territory);
        }

        $products = $query->get();

        return response()->json(['success' => true, 'data' => $products]);
    }

    /** POST /api/customer/insurance/quote */
    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:insurance_products,id'],
            'travelers' => ['required', 'array', 'min:1'],
            'travelers.*.age' => ['required', 'integer', 'min:0', 'max:120'],
            'coverage_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $product = InsuranceProduct::query()->findOrFail($validated['product_id']);

        try {
            $quote = $this->pricing->quote($product, $validated['travelers'], (int) $validated['coverage_days']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $quote]);
    }

    /** POST /api/customer/insurance/purchase */
    public function purchase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:insurance_products,id'],
            'travelers' => ['required', 'array', 'min:1'],
            'travelers.*.age' => ['required', 'integer', 'min:0', 'max:120'],
            'travelers.*.name' => ['nullable', 'string'],
            'travelers.*.passport' => ['nullable', 'string'],
            'travelers.*.dob' => ['nullable', 'date'],
            'coverage_start_date' => ['required', 'date'],
            'coverage_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $product = InsuranceProduct::query()->findOrFail($validated['product_id']);

        try {
            $policy = $this->policy->issue(
                $product,
                $validated['travelers'],
                (string) $validated['coverage_start_date'],
                (int) $validated['coverage_days'],
                user: $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $policy], 201);
    }

    /** GET /api/customer/insurance/policies — my policies */
    public function myPolicies(Request $request): JsonResponse
    {
        $policies = InsurancePolicy::query()
            ->with('product:id,product_name,underwriter_name')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('issued_at')
            ->get();

        return response()->json(['success' => true, 'data' => $policies]);
    }

    /** GET /api/customer/insurance/policies/{id} */
    public function showPolicy(Request $request, string $id): JsonResponse
    {
        $policy = InsurancePolicy::query()
            ->with('product')
            ->where('user_id', $request->user()->id)
            ->find($id);

        if ($policy === null) {
            return response()->json(['success' => false, 'message' => 'Policy not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $policy]);
    }
}

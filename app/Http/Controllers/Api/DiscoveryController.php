<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Catalog\DiscoveryService;
use App\Services\Offers\OfferVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiscoveryController extends Controller
{
    public function __construct(
        private readonly OfferVisibilityService $offerVisibilityService
    ) {
    }

    public function search(Request $request, DiscoveryService $discoveryService): JsonResponse
    {
        $validated = $request->validate([
            'module_type' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['flight', 'hotel', 'transfer', 'package', 'car', 'excursion', 'visa']),
            ],
            'from_location' => ['sometimes', 'nullable', 'string'],
            'to_location' => ['sometimes', 'nullable', 'string'],
            'destination' => ['sometimes', 'nullable', 'string'],
            'start_date' => ['sometimes', 'nullable', 'string'],
            'end_date' => ['sometimes', 'nullable', 'string'],
            'adults' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'children' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'price_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'price_max' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(['price_asc', 'price_desc', 'newest'])],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cabin_class' => ['sometimes', 'nullable', 'string'],
            'stars' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            'meal_type' => ['sometimes', 'nullable', 'string'],
            'min_rating' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10'],
            'vehicle_type' => ['sometimes', 'nullable', 'string'],
        ]);

        $input = [
            'module_type' => $validated['module_type'] ?? null,
            'from_location' => $validated['from_location'] ?? null,
            'to_location' => $validated['to_location'] ?? null,
            'destination' => $validated['destination'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'adults' => $validated['adults'] ?? null,
            'children' => $validated['children'] ?? 0,
            'price_min' => $validated['price_min'] ?? null,
            'price_max' => $validated['price_max'] ?? null,
            'currency' => isset($validated['currency']) ? strtoupper($validated['currency']) : null,
            'free_cancellation' => $this->parseBooleanish($request->query('free_cancellation')),
            'is_package_eligible' => $this->parseBooleanish($request->query('is_package_eligible')),
            'sort' => $validated['sort'] ?? null,
            'per_page' => $validated['per_page'] ?? null,
            'page' => $validated['page'] ?? null,
            'is_direct' => $this->parseBooleanish($request->query('is_direct')),
            'has_baggage' => $this->parseBooleanish($request->query('has_baggage')),
            'cabin_class' => $validated['cabin_class'] ?? null,
            'stars' => $validated['stars'] ?? null,
            'meal_type' => $validated['meal_type'] ?? null,
            'min_rating' => $validated['min_rating'] ?? null,
            'vehicle_type' => $validated['vehicle_type'] ?? null,
            'private_only' => $this->parseBooleanish($request->query('private_only')),
        ];

        $lang = $request->attributes->get('lang');
        $lang = is_string($lang) && $lang !== '' ? $lang : null;

        // TODO: apply visibility filter when direct queries are added
        $result = $discoveryService->search($input, $lang);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function show(int $id, Request $request, DiscoveryService $discoveryService): JsonResponse
    {
        $lang = $request->attributes->get('lang');
        $lang = is_string($lang) && $lang !== '' ? $lang : null;

        $payload = $discoveryService->findPublishedOfferWithNormalized($id, $lang);

        if ($payload === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    private function parseBooleanish(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'on'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'off'], true)) {
                return false;
            }

            return null;
        }

        return null;
    }
}

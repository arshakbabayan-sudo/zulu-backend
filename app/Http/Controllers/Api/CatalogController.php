<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CatalogOfferDetailResource;
use App\Http\Resources\Api\CatalogOfferResource;
use App\Services\Catalog\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function offers(Request $request, CatalogService $catalogService): JsonResponse
    {
        $validated = $request->validate([
            'type' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['flight', 'hotel', 'transfer', 'car', 'excursion', 'package', 'visa']),
            ],
        ]);

        $type = $validated['type'] ?? null;
        $offers = $catalogService->listPublishedOffers($type);

        return response()->json([
            'success' => true,
            'data' => CatalogOfferResource::collection($offers)->resolve(),
        ]);
    }

    public function show(Request $request, string $id, CatalogService $catalogService): JsonResponse
    {
        if (! ctype_digit($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        $offer = $catalogService->findPublishedOfferForPublicDetail((int) $id);

        if ($offer === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => CatalogOfferDetailResource::make($offer)->toArray($request),
        ]);
    }
}

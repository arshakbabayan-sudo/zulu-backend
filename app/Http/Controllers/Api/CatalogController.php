<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CatalogOfferDetailResource;
use App\Http\Resources\Api\CatalogOfferResource;
use App\Services\Catalog\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            // Part A — DISPLAY currency (how prices are presented). The resources
            // read it off the request; the cache key MUST include it so a USD
            // and an AMD request don't share a cached payload.
            'display_currency' => ['sometimes', 'nullable', 'string', Rule::in(['USD', 'EUR', 'AMD'])],
        ]);

        $type = $validated['type'] ?? null;
        $displayCurrency = isset($validated['display_currency']) ? strtoupper($validated['display_currency']) : null;
        $lang = $request->attributes->get('lang');
        $lang = is_string($lang) && $lang !== '' ? $lang : (string) config('app.locale', 'en');
        $cacheKey = 'catalog_offers:'.md5(json_encode([$type, $lang, $displayCurrency], JSON_UNESCAPED_UNICODE));
        $data = Cache::remember($cacheKey, 30, function () use ($catalogService, $type, $request) {
            $offers = $catalogService->listPublishedOffers($type);

            return CatalogOfferResource::collection($offers)->resolve($request);
        });

        return response()->json([
            'success' => true,
            'data' => $data,
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PackageResource;
use App\Services\Packages\PackageSearchService;
use App\Services\Packages\PackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public B2C package search and read (no auth).
 */
class StorefrontPackageController extends Controller
{
    public function search(Request $request, PackageSearchService $packageSearchService): JsonResponse
    {
        $validated = $request->validate([
            'destination_country' => ['sometimes', 'nullable', 'string'],
            'destination_city' => ['sometimes', 'nullable', 'string'],
            'duration_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'adults_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'nullable', 'string'],
            'date_to' => ['sometimes', 'nullable', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $result = $packageSearchService->search($validated);

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'meta' => $result['meta'],
            'fallback_used' => $result['fallback_used'],
        ]);
    }

    public function show(string $package, PackageService $packageService): JsonResponse
    {
        try {
            $model = $packageService->findPublicForStorefront($package);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('StorefrontPackage show find failed', [
                'package' => $package,
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'class' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Server error',
                'debug' => [
                    'where' => 'findPublicForStorefront',
                    'class' => get_class($e),
                    'error' => $e->getMessage(),
                    'file' => basename($e->getFile()).':'.$e->getLine(),
                ],
            ], 500);
        }

        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        try {
            $data = PackageResource::make($model)->toArray(request());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('StorefrontPackage show resource failed', [
                'package' => $package,
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'class' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Server error',
                'debug' => [
                    'where' => 'PackageResource::toArray',
                    'class' => get_class($e),
                    'error' => $e->getMessage(),
                    'file' => basename($e->getFile()).':'.$e->getLine(),
                ],
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function pricing(string $package, PackageService $packageService): JsonResponse
    {
        $model = $packageService->findPublicForStorefront($package);
        if ($model === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        $pricing = $packageService->composePricing($model);

        return response()->json([
            'success' => true,
            'data' => $pricing,
        ]);
    }
}

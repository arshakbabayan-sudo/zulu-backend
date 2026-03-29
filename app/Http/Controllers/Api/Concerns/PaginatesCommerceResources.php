<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

trait PaginatesCommerceResources
{
    /** Default 20 per roadmap; capped for safety when clients pass per_page. */
    protected function commerceListPerPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 20);

        return max(1, min($perPage, 100));
    }

    protected function paginatedCommerceResourceResponse(Request $request, LengthAwarePaginator $paginator, string $resourceClass): JsonResponse
    {
        /** @var class-string<JsonResource> $resourceClass */
        return response()->json([
            'success' => true,
            'data' => $resourceClass::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }
}

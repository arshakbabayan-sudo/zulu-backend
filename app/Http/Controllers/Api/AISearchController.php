<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\AISearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * AI-powered search endpoint (PART 34 Phase 1).
 *
 * Public + rate-limited endpoint. User submits natural-language query;
 * returns Claude-parsed filters + actual search results.
 */
class AISearchController extends Controller
{
    public function __construct(
        private AISearchService $aiSearch,
    ) {}

    /**
     * POST /api/ai/search
     *
     * Body: {"query": "hotel in Yerevan with beach, under 200$, December", "lang": "en"}
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:500'],
            'lang' => ['nullable', 'string', 'in:en,hy,ru'],
        ]);

        try {
            $result = $this->aiSearch->search([
                'query' => $validated['query'],
                'lang' => $validated['lang'] ?? 'en',
            ]);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }
    }
}

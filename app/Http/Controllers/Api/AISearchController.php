<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\AISearchService;
use App\Services\Catalog\DiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * AI-powered search endpoint (PART 34 Phase 1).
 *
 * Public + rate-limited endpoint. User submits natural-language query;
 * returns AI-parsed filters + actual search results.
 *
 * If the AI provider is unavailable (no key, quota exceeded, network),
 * we fall back to running the raw text through Meilisearch via
 * DiscoveryService so the user always sees results — never a 503.
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
    public function search(Request $request, DiscoveryService $discovery): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:500'],
            'lang' => ['nullable', 'string', 'in:en,hy,ru'],
        ]);

        $lang = $validated['lang'] ?? 'en';

        try {
            $result = $this->aiSearch->search([
                'query' => $validated['query'],
                'lang' => $lang,
            ]);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (RuntimeException $e) {
            // AI provider unavailable — give the user results anyway.
            $results = $discovery->search([
                'q' => $validated['query'],
                'per_page' => 20,
                'sort' => 'price_asc',
            ], $lang);

            return response()->json([
                'success' => true,
                'data' => [
                    'parsed_filters' => null,
                    'search_results' => $results,
                    'ai_provider' => (string) config('ai.driver', 'gemini'),
                    'ai_status' => 'unavailable',
                    'ai_message' => $e->getMessage(),
                ],
            ]);
        }
    }
}

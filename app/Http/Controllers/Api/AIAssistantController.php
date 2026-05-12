<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\AIChatbotService;
use App\Services\AI\AIRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-facing AI endpoints beyond search:
 *   - POST /api/ai/chat              → conversational assistant
 *   - GET  /api/ai/recommendations    → personalized package picks
 *
 * Both endpoints degrade gracefully when ANTHROPIC_API_KEY is unset:
 *   - chat returns a fallback message + success=false
 *   - recommendations return the heuristic list with reason=null
 */
class AIAssistantController extends Controller
{
    public function __construct(
        private AIChatbotService $chatbot,
        private AIRecommendationService $recommender,
    ) {}

    /**
     * POST /api/ai/chat
     *
     * Body: {
     *   "query": "When is my next trip?",
     *   "lang": "en"|"hy"|"ru" (optional, default en),
     *   "history": [ {role: "user"|"assistant", content: "..."} ] (optional)
     * }
     */
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:2000'],
            'lang' => ['nullable', 'string', 'in:en,hy,ru'],
            'history' => ['nullable', 'array', 'max:50'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:8000'],
        ]);

        $result = $this->chatbot->send(
            query: (string) $validated['query'],
            history: (array) ($validated['history'] ?? []),
            user: $request->user(),
            lang: (string) ($validated['lang'] ?? 'en'),
        );

        return response()->json([
            'success' => $result['success'],
            'data' => [
                'reply' => $result['reply'],
                'history' => $result['history'],
            ],
            'error' => $result['error'] ?? null,
        ], $result['success'] ? 200 : 503);
    }

    /**
     * GET /api/ai/recommendations?limit=6
     *
     * Auth required. Returns recommended packages for the authenticated
     * user. AI-reranked when ANTHROPIC_API_KEY is set; falls back to
     * heuristic ordering otherwise.
     */
    public function recommendations(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
            ], 401);
        }

        $limit = max(1, min(20, (int) $request->query('limit', 6)));
        $rows = $this->recommender->recommendForUser($user, $limit);

        $data = array_map(function ($row) {
            $pkg = $row['package'];

            return [
                'id' => $pkg->id,
                'title' => $pkg->title ?? null,
                'destination_country' => $pkg->destination_country ?? null,
                'base_price' => $pkg->base_price ?? null,
                'currency' => $pkg->currency ?? null,
                'cover_url' => $pkg->cover_url ?? null,
                'score' => $row['score'],
                'reason' => $row['reason'],
            ];
        }, $rows);

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'ai_reranked' => $this->recommender->isAiAvailable(),
                'count' => count($data),
            ],
        ]);
    }
}

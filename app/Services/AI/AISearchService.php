<?php

namespace App\Services\AI;

use App\Services\Catalog\DiscoveryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * AI-powered semantic search service.
 *
 * Sprint 46 (PART 34 Phase 1): user types a natural-language query
 * (e.g. "hotel in Yerevan with beach access, under 200$, December") and
 * Anthropic Claude parses it into structured DiscoveryService filters.
 *
 * Provider: Anthropic Claude (per ADR — Armenian language support is the
 * deciding factor; OpenAI's Armenian is weaker).
 *
 * Backend flow:
 *   1. Receive {query: string, lang: 'en'|'hy'|'ru'}
 *   2. Call Claude with carefully constructed prompt + JSON schema
 *   3. Parse Claude's response into DiscoveryService::search() input shape
 *   4. Run regular discovery search with those filters
 *   5. Return: {parsed_filters, search_results, debug?}
 */
class AISearchService
{
    public function __construct(
        private DiscoveryService $discoveryService,
    ) {}

    /**
     * @param  array{query: string, lang?: string}  $input
     * @return array{parsed_filters: array<string, mixed>, search_results: array<string, mixed>, ai_explanation?: string}
     */
    public function search(array $input): array
    {
        $query = trim($input['query'] ?? '');
        $lang = $input['lang'] ?? 'en';

        if ($query === '') {
            throw new RuntimeException('Query is empty.');
        }

        $apiKey = (string) config('services.anthropic.api_key', env('ANTHROPIC_API_KEY', ''));
        if ($apiKey === '') {
            throw new RuntimeException('Anthropic API key is not configured. Set ANTHROPIC_API_KEY in .env.');
        }

        $parsed = $this->parseWithClaude($query, $lang, $apiKey);

        // Run actual discovery search using parsed filters
        $searchInput = $this->mapToDiscoveryInput($parsed);
        $results = $this->discoveryService->search($searchInput, $lang);

        return [
            'parsed_filters' => $parsed,
            'search_results' => $results,
            'ai_explanation' => $parsed['_explanation'] ?? null,
        ];
    }

    /**
     * Call Claude API to parse natural language into structured filters.
     *
     * @return array<string, mixed>
     */
    private function parseWithClaude(string $query, string $lang, string $apiKey): array
    {
        $systemPrompt = <<<'TXT'
You are a search-query parser for ZULU travel platform. The user types a
natural-language query (in English, Armenian, or Russian). You must return
ONLY a JSON object with the following keys (no prose, no markdown fences):

{
  "module_type": one of ["flight", "hotel", "transfer", "car", "excursion", "package", null],
  "from_city": string|null,
  "to_city": string|null,
  "destination_city": string|null,
  "date_from": "YYYY-MM-DD"|null,
  "date_to": "YYYY-MM-DD"|null,
  "max_price": number|null,
  "min_price": number|null,
  "currency": string|null (USD, EUR, AMD, RUB),
  "guests_adults": int|null,
  "guests_children": int|null,
  "amenities": string[]|null (e.g. ["wifi", "pool", "breakfast"]),
  "_explanation": string (short explanation of what you parsed, in user's language)
}

Rules:
- If the query is ambiguous, set module_type to null and try to extract general filters.
- Dates must be ISO 8601 (YYYY-MM-DD). Resolve relative dates (e.g. "next month") relative to today.
- For Armenian/Russian queries, parse city names to their English form (Երեւան → Yerevan, Москва → Moscow).
- Never invent values — if a filter isn't mentioned, set it to null.
- Output JSON only. No markdown, no explanation outside the JSON _explanation field.
TXT;

        $today = now()->format('Y-m-d');
        $userMessage = "Today is {$today}. User query (lang={$lang}): {$query}";

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 1024,
                    'system' => $systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Claude API error', ['status' => $response->status(), 'body' => $response->body()]);
                throw new RuntimeException('AI search failed: '.$response->status());
            }

            $payload = $response->json();
            $text = $payload['content'][0]['text'] ?? '';
            $parsed = json_decode($text, true);

            if (! is_array($parsed)) {
                Log::warning('Claude returned non-JSON', ['text' => $text]);
                throw new RuntimeException('AI parser returned invalid JSON.');
            }

            return $parsed;
        } catch (\Throwable $e) {
            Log::error('AISearchService::parseWithClaude failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('AI search temporarily unavailable: '.$e->getMessage());
        }
    }

    /**
     * Map AI parsed filters to DiscoveryService::search() input shape.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function mapToDiscoveryInput(array $parsed): array
    {
        $input = [];

        if (! empty($parsed['module_type'])) {
            $input['module_type'] = $parsed['module_type'];
        }

        if (! empty($parsed['from_city'])) {
            $input['from'] = $parsed['from_city'];
        }

        if (! empty($parsed['to_city']) || ! empty($parsed['destination_city'])) {
            $input['to'] = $parsed['to_city'] ?? $parsed['destination_city'];
        }

        if (! empty($parsed['date_from'])) {
            $input['date_from'] = $parsed['date_from'];
        }

        if (! empty($parsed['date_to'])) {
            $input['date_to'] = $parsed['date_to'];
        }

        if (isset($parsed['max_price']) && is_numeric($parsed['max_price'])) {
            $input['max_price'] = (float) $parsed['max_price'];
        }

        if (isset($parsed['min_price']) && is_numeric($parsed['min_price'])) {
            $input['min_price'] = (float) $parsed['min_price'];
        }

        if (! empty($parsed['currency'])) {
            $input['currency'] = $parsed['currency'];
        }

        $input['per_page'] = 20;
        $input['sort'] = 'newest';

        return $input;
    }
}

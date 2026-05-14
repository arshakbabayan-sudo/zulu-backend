<?php

namespace App\Services\AI;

use App\Services\Catalog\DiscoveryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * AI-powered semantic search service.
 *
 * Provider-agnostic: dispatches to whichever AI vendor `AI_PROVIDER` env is
 * pointing at — Gemini today (free tier, no card required), Claude when
 * ANTHROPIC_API_KEY is supplied. Adding another provider (OpenAI, Mistral,
 * etc.) is one new private parse method + one match arm in `parseQuery`.
 *
 * Backend flow:
 *   1. Receive {query: string, lang: 'en'|'hy'|'ru'}
 *   2. Call active provider with shared prompt + JSON-only response
 *   3. Map parsed filters to DiscoveryService::search() input
 *   4. Run regular discovery search with those filters
 *   5. Return: {parsed_filters, search_results, ai_provider, ai_explanation?}
 */
class AISearchService
{
    public function __construct(
        private DiscoveryService $discoveryService,
    ) {}

    /**
     * @param  array{query: string, lang?: string}  $input
     * @return array{parsed_filters: array<string, mixed>, search_results: array<string, mixed>, ai_provider: string, ai_explanation?: string|null}
     */
    public function search(array $input): array
    {
        $query = trim($input['query'] ?? '');
        $lang = $input['lang'] ?? 'en';

        if ($query === '') {
            throw new RuntimeException('Query is empty.');
        }

        $provider = (string) config('ai.driver', env('AI_PROVIDER', 'gemini'));

        $parsed = match ($provider) {
            'claude' => $this->parseWithClaude($query, $lang),
            default => $this->parseWithGemini($query, $lang),
        };

        $searchInput = $this->mapToDiscoveryInput($parsed);
        // Always pass the raw query through to Meilisearch — typo / fuzzy
        // catches anything the parser missed.
        if (! isset($searchInput['q']) || $searchInput['q'] === '') {
            $searchInput['q'] = $query;
        }

        $results = $this->discoveryService->search($searchInput, $lang);

        return [
            'parsed_filters' => $parsed,
            'search_results' => $results,
            'ai_provider' => $provider,
            'ai_explanation' => $parsed['_explanation'] ?? null,
        ];
    }

    /**
     * Google Gemini implementation. Uses gemini-2.0-flash via the v1beta
     * REST endpoint. Free tier: 1,500 req/day, no credit card required.
     *
     * @return array<string, mixed>
     */
    private function parseWithGemini(string $query, string $lang): array
    {
        $apiKey = (string) config('ai.gemini.api_key', env('GEMINI_API_KEY', ''));
        if ($apiKey === '') {
            throw new RuntimeException('Gemini API key is not configured. Set GEMINI_API_KEY in .env.');
        }

        $model = (string) config('ai.gemini.model', env('GEMINI_MODEL', 'gemini-2.0-flash'));
        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            urlencode($model)
        );

        $prompt = $this->buildPrompt($query, $lang);

        try {
            $response = Http::timeout(15)
                ->withQueryParameters(['key' => $apiKey])
                ->acceptJson()
                ->asJson()
                ->post($endpoint, [
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.1,
                        'maxOutputTokens' => 512,
                        'responseMimeType' => 'application/json',
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Gemini API error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 500)]);
                throw new RuntimeException('AI search failed: '.$response->status());
            }

            $text = (string) ($response->json('candidates.0.content.parts.0.text') ?? '');
            $parsed = json_decode($text, true);
            if (! is_array($parsed)) {
                Log::warning('Gemini returned non-JSON', ['text' => substr($text, 0, 500)]);
                throw new RuntimeException('AI parser returned invalid JSON.');
            }

            return $parsed;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('AISearchService::parseWithGemini failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('AI search temporarily unavailable: '.$e->getMessage());
        }
    }

    /**
     * Anthropic Claude implementation. Activated when AI_PROVIDER=claude and
     * ANTHROPIC_API_KEY is supplied.
     *
     * @return array<string, mixed>
     */
    private function parseWithClaude(string $query, string $lang): array
    {
        $apiKey = (string) config('ai.claude.api_key', env('ANTHROPIC_API_KEY', ''));
        if ($apiKey === '') {
            throw new RuntimeException('Anthropic API key is not configured. Set ANTHROPIC_API_KEY in .env.');
        }

        $model = (string) config('ai.claude.model', env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'));
        $systemPrompt = $this->systemPromptForClaude();
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
                    'model' => $model,
                    'max_tokens' => 1024,
                    'system' => $systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Claude API error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 500)]);
                throw new RuntimeException('AI search failed: '.$response->status());
            }

            $text = (string) ($response->json('content.0.text') ?? '');
            $parsed = json_decode($text, true);
            if (! is_array($parsed)) {
                Log::warning('Claude returned non-JSON', ['text' => substr($text, 0, 500)]);
                throw new RuntimeException('AI parser returned invalid JSON.');
            }

            return $parsed;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('AISearchService::parseWithClaude failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('AI search temporarily unavailable: '.$e->getMessage());
        }
    }

    /**
     * Shared prompt for both Gemini and Claude. Both providers receive the
     * same instruction set so the parsed shape stays consistent — DiscoveryService
     * doesn't care which model produced the filters.
     */
    private function buildPrompt(string $query, string $lang): string
    {
        $today = now()->format('Y-m-d');

        return <<<PROMPT
You are a search-query parser for ZULU travel platform. Today is {$today}.
The user typed in language: {$lang}.

User query:
"""
{$query}
"""

Return ONLY a JSON object (no prose, no markdown fences) with these keys:

{
  "module_type": one of ["flight","hotel","transfer","car","excursion","package","visa"] or null,
  "from_city": string or null,
  "to_city": string or null,
  "destination_city": string or null,
  "date_from": "YYYY-MM-DD" or null,
  "date_to": "YYYY-MM-DD" or null,
  "max_price": number or null,
  "min_price": number or null,
  "currency": "USD" | "EUR" | "AMD" | "RUB" or null,
  "guests_adults": integer or null,
  "guests_children": integer or null,
  "amenities": string array or null,
  "_explanation": short sentence in the user's language summarising what you parsed
}

Rules:
- Omit (set null) any key you cannot infer with high confidence — do NOT invent.
- Dates are ISO 8601. Resolve relative dates ("next month", "next week") relative to today {$today}.
- For Armenian / Russian queries, normalise city names to English (Երեւան → Yerevan, Москва → Moscow).
- Output JSON ONLY. No markdown, no prose outside _explanation.
PROMPT;
    }

    private function systemPromptForClaude(): string
    {
        return <<<'TXT'
You are a search-query parser for ZULU travel platform. The user types a
natural-language query (in English, Armenian, or Russian). Return ONLY a JSON
object (no prose, no markdown fences) with these keys:

{
  "module_type": one of ["flight","hotel","transfer","car","excursion","package","visa"] or null,
  "from_city": string or null,
  "to_city": string or null,
  "destination_city": string or null,
  "date_from": "YYYY-MM-DD" or null,
  "date_to": "YYYY-MM-DD" or null,
  "max_price": number or null,
  "min_price": number or null,
  "currency": "USD"|"EUR"|"AMD"|"RUB" or null,
  "guests_adults": integer or null,
  "guests_children": integer or null,
  "amenities": string array or null,
  "_explanation": short sentence in the user's language
}

Rules:
- Omit keys you cannot infer — never invent.
- Dates are ISO 8601, resolve relative to today.
- Normalise non-Latin city names to English.
- Output JSON only.
TXT;
    }

    /**
     * Map AI parsed filters to DiscoveryService::search() input shape.
     * Keep this in one place so adding new filters (e.g. stars, meal_type)
     * is one entry per AI-key → DiscoveryService-key mapping.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function mapToDiscoveryInput(array $parsed): array
    {
        $input = [];

        if (! empty($parsed['module_type'])) {
            $input['module_type'] = (string) $parsed['module_type'];
        }
        if (! empty($parsed['from_city'])) {
            $input['from_location'] = (string) $parsed['from_city'];
        }
        if (! empty($parsed['to_city']) || ! empty($parsed['destination_city'])) {
            $input['to_location'] = (string) ($parsed['to_city'] ?? $parsed['destination_city']);
            $input['destination'] = (string) ($parsed['destination_city'] ?? $parsed['to_city']);
        }
        if (! empty($parsed['date_from'])) {
            $input['start_date'] = (string) $parsed['date_from'];
        }
        if (! empty($parsed['date_to'])) {
            $input['end_date'] = (string) $parsed['date_to'];
        }
        if (isset($parsed['max_price']) && is_numeric($parsed['max_price'])) {
            $input['price_max'] = (float) $parsed['max_price'];
        }
        if (isset($parsed['min_price']) && is_numeric($parsed['min_price'])) {
            $input['price_min'] = (float) $parsed['min_price'];
        }
        if (! empty($parsed['currency'])) {
            $input['currency'] = strtoupper(substr((string) $parsed['currency'], 0, 3));
        }
        if (isset($parsed['guests_adults']) && is_numeric($parsed['guests_adults'])) {
            $input['adults'] = (int) $parsed['guests_adults'];
        }
        if (isset($parsed['guests_children']) && is_numeric($parsed['guests_children'])) {
            $input['children'] = (int) $parsed['guests_children'];
        }

        $input['per_page'] = 20;
        $input['sort'] = 'price_asc';

        return $input;
    }
}

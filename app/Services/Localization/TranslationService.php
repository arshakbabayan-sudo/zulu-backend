<?php

namespace App\Services\Localization;

use App\Models\SupportedLanguage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Translate short-to-medium content snippets (hotel names, descriptions,
 * UI strings) between the platform's supported languages via the Claude
 * API. Reuses the same ANTHROPIC_API_KEY env var as AISearchService.
 *
 * Returns a map of {locale => translated_value}, with the source locale
 * omitted from the output (caller already has that value).
 */
class TranslationService
{
    private const MODEL = 'claude-haiku-4-5-20251001';

    private const MAX_TOKENS = 1024;

    private const HTTP_TIMEOUT_SECONDS = 30;

    /**
     * Translate a single string into every supported language EXCEPT the
     * source. Source language is auto-detected by Claude (or supplied via
     * `$sourceLocale`).
     *
     * @return array<string, string> e.g. ['ru' => '...', 'en' => '...']
     */
    public function translate(string $text, ?string $sourceLocale = null, ?array $targetLocales = null): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $supported = $this->supportedLocales();
        if ($targetLocales === null) {
            $targetLocales = $supported;
        }
        $targetLocales = array_values(array_filter(
            array_map('strtolower', $targetLocales),
            fn (string $loc): bool => in_array($loc, $supported, true)
                && ($sourceLocale === null || $loc !== strtolower($sourceLocale))
        ));

        if ($targetLocales === []) {
            return [];
        }

        $apiKey = (string) config('ai.claude.api_key', env('ANTHROPIC_API_KEY', ''));
        if ($apiKey === '') {
            Log::warning('TranslationService: ANTHROPIC_API_KEY is empty; skipping translation.');

            return [];
        }

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => self::MODEL,
                    'max_tokens' => self::MAX_TOKENS,
                    'system' => $this->systemPrompt(),
                    'messages' => [
                        ['role' => 'user', 'content' => $this->userPrompt($text, $sourceLocale, $targetLocales)],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('TranslationService: Claude API non-2xx', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                ]);

                return [];
            }

            $rawText = (string) ($response->json('content.0.text') ?? '');
            $parsed = $this->extractJsonObject($rawText);
            if (! is_array($parsed)) {
                Log::warning('TranslationService: Claude returned non-JSON', ['text' => substr($rawText, 0, 300)]);

                return [];
            }

            // Keep only the requested target locales; drop anything else
            // Claude decided to emit (defensive).
            $out = [];
            foreach ($targetLocales as $loc) {
                $value = $parsed[$loc] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $out[$loc] = trim($value);
                }
            }

            return $out;
        } catch (Throwable $e) {
            Log::error('TranslationService::translate failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Translate many field values in a single Claude call. Saves round
     * trips when an admin saves a hotel with five translatable fields at
     * once.
     *
     * @param  array<string, string>  $fields  field_name => source value
     * @return array<string, array<string, string>> field_name => (locale => value)
     */
    public function translateMany(array $fields, ?string $sourceLocale = null, ?array $targetLocales = null): array
    {
        $out = [];
        foreach ($fields as $field => $value) {
            if (! is_string($value)) {
                continue;
            }
            $translated = $this->translate($value, $sourceLocale, $targetLocales);
            if ($translated !== []) {
                $out[$field] = $translated;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function supportedLocales(): array
    {
        try {
            return SupportedLanguage::query()
                ->where('is_enabled', true)
                ->pluck('code')
                ->map(fn ($c) => strtolower((string) $c))
                ->all();
        } catch (Throwable) {
            return ['en', 'ru', 'hy'];
        }
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
You are a professional translator for ZULU travel platform. You translate
short-to-medium content snippets (hotel names, descriptions, addresses,
UI labels) between English (en), Russian (ru), and Armenian (hy).

Rules:
- Output ONLY a JSON object mapping target locale code to its translation. No markdown fences, no prose.
- Preserve proper nouns (hotel chain names, city names) in their idiomatic form for each language. E.g.: "Marriott" stays "Marriott" in en, becomes "Марриотт" in ru, "Մարիոթ" in hy.
- Preserve numbers, dates, and brand names exactly.
- Keep the same tone (formal/marketing/descriptive) as the source.
- Do NOT add explanations or alternative options — one translation per locale.
TXT;
    }

    /**
     * @param  list<string>  $targetLocales
     */
    private function userPrompt(string $text, ?string $sourceLocale, array $targetLocales): string
    {
        $targets = implode(', ', $targetLocales);
        $source = $sourceLocale !== null
            ? "Source language: {$sourceLocale}."
            : 'Detect the source language yourself.';
        $targetExample = [];
        foreach ($targetLocales as $loc) {
            $targetExample[$loc] = '...';
        }
        $exampleJson = json_encode($targetExample, JSON_UNESCAPED_UNICODE);

        return <<<TXT
Translate the following text into these target locales: {$targets}.
{$source}

Return JSON in this exact shape (one key per target locale):
{$exampleJson}

Text to translate:
"""
{$text}
"""
TXT;
    }

    /**
     * Copied from AISearchService — Claude occasionally wraps JSON in
     * markdown fences despite the prompt. Strip them before json_decode.
     *
     * @return array<string, mixed>|null
     */
    private function extractJsonObject(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $m) === 1) {
            $text = $m[1];
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        $parsed = json_decode($text, true);

        return is_array($parsed) ? $parsed : null;
    }
}

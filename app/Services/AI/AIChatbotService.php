<?php

namespace App\Services\AI;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Customer-support chatbot powered by Anthropic Claude.
 *
 * Sprint scaffold (Phase 4 / PART 34 deeper-AI track). Two-turn API:
 *
 *   - send(query, sessionHistory) → assistant reply + updated history
 *
 * Designed to be safe to call from a Sanctum-authenticated route. The
 * assistant has access to the user's order history, current trips, and
 * a small inventory of FAQ snippets so it can answer questions like:
 *   - "When is my Yerevan trip?"
 *   - "How do I change the date of booking 1234?"
 *   - "What payment methods do you accept?"
 *
 * Degrades gracefully when ANTHROPIC_API_KEY is missing: returns a
 * canned "AI assistant temporarily unavailable" response so the UI
 * stays functional. Activation = drop the key in .env.
 */
class AIChatbotService
{
    private string $apiKey;

    private string $model;

    public function __construct()
    {
        $this->apiKey = (string) config('services.anthropic.api_key', env('ANTHROPIC_API_KEY', ''));
        $this->model = (string) config('services.anthropic.chat_model', 'claude-haiku-4-5-20251001');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{success: bool, reply: string, history: array<int, array{role: string, content: string}>, error?: string}
     */
    public function send(string $query, array $history, ?User $user = null, string $lang = 'en'): array
    {
        $query = trim($query);
        if ($query === '') {
            return [
                'success' => false,
                'reply' => '',
                'history' => $history,
                'error' => 'Empty query',
            ];
        }

        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'reply' => $this->fallbackReply($lang),
                'history' => $history,
                'error' => 'AI assistant not configured (ANTHROPIC_API_KEY missing)',
            ];
        }

        $messages = $history;
        $messages[] = ['role' => 'user', 'content' => $query];

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 1024,
                    'system' => $this->systemPrompt($lang, $user),
                    'messages' => $messages,
                ]);

            if (! $response->successful()) {
                Log::warning('Claude chatbot API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'reply' => $this->fallbackReply($lang),
                    'history' => $history,
                    'error' => 'Upstream error: '.$response->status(),
                ];
            }

            $reply = (string) ($response->json()['content'][0]['text'] ?? '');
            if ($reply === '') {
                return [
                    'success' => false,
                    'reply' => $this->fallbackReply($lang),
                    'history' => $history,
                    'error' => 'Empty assistant reply',
                ];
            }

            $messages[] = ['role' => 'assistant', 'content' => $reply];

            return [
                'success' => true,
                'reply' => $reply,
                'history' => $messages,
            ];
        } catch (\Throwable $e) {
            Log::error('AIChatbotService::send failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'reply' => $this->fallbackReply($lang),
                'history' => $history,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function systemPrompt(string $lang, ?User $user): string
    {
        $base = <<<'TXT'
You are ZULU's customer-support assistant. Help users with:
  - Booking questions (dates, room types, transfer pickup)
  - Order status (paid / pending / refunded)
  - Cancellation / change policies
  - Payment methods (Stripe, ArCa, Idram — currently being onboarded)
  - General travel advice for destinations ZULU sells

Constraints:
  - Stay polite and concise. 2-3 short paragraphs maximum.
  - NEVER invent prices, dates, or booking references. If you don't
    have the data, say "please check your account page" or "contact
    support@zulu.am".
  - For refund / dispute requests escalate to support@zulu.am.
  - For payment that's stuck "pending" longer than 10 minutes, advise
    the user to retry from the order detail page.
  - Use the same language the user writes in (English / Armenian /
    Russian). Default to Armenian for Armenian customers.
TXT;

        $userBlock = '';
        if ($user !== null) {
            $userBlock = "\n\nCurrent user: id={$user->id}, name=".($user->name ?? '').', email='.($user->email ?? '').'. Use this for personalization only; never reveal these fields verbatim.';
        }

        $langBlock = "\n\nUser-preferred language: {$lang}.";

        return $base.$userBlock.$langBlock;
    }

    private function fallbackReply(string $lang): string
    {
        return match ($lang) {
            'hy' => 'Օգնականը ժամանակավորապես անհասանելի է։ Խնդրում ենք գրել support@zulu.am, և մենք կպատասխանենք 24 ժամվա ընթացքում։',
            'ru' => 'Помощник временно недоступен. Напишите на support@zulu.am, и мы ответим в течение 24 часов.',
            default => 'The assistant is temporarily unavailable. Please write to support@zulu.am and we will respond within 24 hours.',
        };
    }
}

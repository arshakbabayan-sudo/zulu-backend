<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram alert sender for prod health monitoring (Sprint 78).
 *
 * Reads TELEGRAM_BOT_TOKEN + TELEGRAM_CHAT_ID from env. Same bot used by
 * the GitHub Actions deploy notifications (Zulu Deploy Bot).
 *
 * Gracefully no-ops if either env var is missing (so dev / CI doesn't try
 * to call Telegram).
 */
class TelegramAlertService
{
    public function send(string $message, bool $silent = false): bool
    {
        $token = (string) (config('services.telegram.bot_token') ?? env('TELEGRAM_BOT_TOKEN'));
        $chatId = (string) (config('services.telegram.chat_id') ?? env('TELEGRAM_CHAT_ID'));

        if ($token === '' || $chatId === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(8)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                    'disable_notification' => $silent ? 'true' : 'false',
                ]);

            if (! $response->ok()) {
                Log::warning('Telegram alert non-200', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Telegram alert send failed', ['message' => $e->getMessage()]);

            return false;
        }
    }
}

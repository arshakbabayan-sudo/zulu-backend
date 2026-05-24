<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Twilio-backed SMS provider. Activated by AppServiceProvider when
 * config('services.twilio.sid') is set. Uses the Twilio REST API over
 * HTTP (Laravel Http facade) so we don't require the twilio/sdk package.
 *
 * Misconfiguration (missing SID / token / from-number) throws on send —
 * the binding only flips to this class when SID is present, but the
 * other two fields are checked here to surface setup mistakes early.
 */
class TwilioSmsProvider implements SmsProvider
{
    public function __construct(
        private readonly string $sid,
        private readonly string $token,
        private readonly string $from,
    ) {}

    public function send(string $to, string $message): bool
    {
        if ($this->sid === '' || $this->token === '' || $this->from === '') {
            throw new RuntimeException('TwilioSmsProvider is missing SID, auth token, or from-number.');
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json";

        try {
            $response = Http::withBasicAuth($this->sid, $this->token)
                ->asForm()
                ->post($url, [
                    'To' => $to,
                    'From' => $this->from,
                    'Body' => $message,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning('Twilio SMS send failed', [
                'to' => $to,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('Twilio SMS exception', [
                'to' => $to,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

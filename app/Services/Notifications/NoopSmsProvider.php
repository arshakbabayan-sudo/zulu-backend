<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Default SMS provider used when no real gateway is configured.
 *
 * Writes the would-be SMS to the `notifications` log channel (falls back
 * to the default channel if `notifications` isn't configured) and returns
 * true so the bulk-send pipeline keeps progressing. This is the "scaffold
 * everything, ship later" stance — UI can already trigger SMS sends,
 * we just don't deliver yet.
 */
class NoopSmsProvider implements SmsProvider
{
    public function send(string $to, string $message): bool
    {
        $this->logger()->info('SMS (noop)', [
            'to' => $to,
            'message' => $message,
        ]);

        return true;
    }

    private function logger(): LoggerInterface
    {
        $channels = (array) config('logging.channels', []);
        if (isset($channels['notifications'])) {
            return Log::channel('notifications');
        }

        return Log::channel(config('logging.default', 'stack'));
    }
}

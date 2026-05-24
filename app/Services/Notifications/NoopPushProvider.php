<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Default push provider used when Firebase (or any FCM gateway) isn't
 * configured. Logs the would-be push and returns true so the bulk-send
 * pipeline doesn't abort.
 */
class NoopPushProvider implements PushProvider
{
    public function sendToUser(int $userId, string $title, string $body): bool
    {
        $this->logger()->info('Push (noop)', [
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
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

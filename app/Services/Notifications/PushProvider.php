<?php

namespace App\Services\Notifications;

/**
 * Provider abstraction for outbound push notifications.
 *
 * Concrete implementations: NoopPushProvider (default, no creds), and
 * FirebasePushProvider (used when config('services.firebase.project_id')
 * is set). Like SmsProvider, implementations should soft-fail and return
 * false instead of throwing on transient errors.
 */
interface PushProvider
{
    /**
     * Deliver a push notification to all device tokens registered for
     * $userId. Returns true on success, false on soft failure (no tokens
     * on file, provider rejected, etc.).
     */
    public function sendToUser(int $userId, string $title, string $body): bool;
}

<?php

namespace App\Services\Notifications;

/**
 * Provider abstraction for outbound SMS.
 *
 * Concrete implementations: NoopSmsProvider (default, no creds), and
 * TwilioSmsProvider (used when config('services.twilio.sid') is set).
 * The binding lives in AppServiceProvider::register().
 *
 * Implementations MUST NOT throw on transient failures — return false
 * and log instead, so the bulk-send flow can carry on across recipients.
 */
interface SmsProvider
{
    /**
     * Deliver $message to phone number $to. Returns true on success,
     * false on a soft failure (provider rejected, no phone on file, etc.).
     */
    public function send(string $to, string $message): bool;
}

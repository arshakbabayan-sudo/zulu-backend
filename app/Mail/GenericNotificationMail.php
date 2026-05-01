<?php

namespace App\Mail;

use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Plain-text-friendly mailable used as the default email body for any
 * Notification row that doesn't have a richer event-specific Mailable
 * (e.g., VoucherIssuedMail). Subject + body come straight from the
 * already-rendered in-app notification (LocalizationService translated
 * them at createForEvent time, so they're already in the recipient's
 * preferred language).
 */
class GenericNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public Notification $notification) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notification->title);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification-generic',
            with: [
                'notification' => $this->notification,
            ],
        );
    }
}

<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mailable for the bulk-broadcast feature on the platform-admin's
 * "Bulk notifications" page. Distinct from GenericNotificationMail
 * (which mirrors a single Notification row's title/message): this one
 * is fed directly from the admin form payload, so it doesn't depend on
 * a Notification model row existing.
 *
 * Subject = $subject, body = $bodyText (plain text, rendered with a
 * branded ZULU layout in emails.bulk-notification).
 */
class AdminBulkNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $subjectText,
        public string $bodyText,
        public string $priority = 'normal',
        public ?string $ctaUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectText);
    }

    public function content(): Content
    {
        $ctaUrl = $this->ctaUrl ?? rtrim((string) config('app.frontend_url', config('app.url', 'https://zulu.am')), '/');

        return new Content(
            view: 'emails.bulk-notification',
            with: [
                'subjectText' => $this->subjectText,
                'bodyText' => $this->bodyText,
                'priority' => $this->priority,
                'ctaUrl' => $ctaUrl,
            ],
        );
    }
}

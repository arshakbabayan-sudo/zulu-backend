<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountDeletionCompleted extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $emailAddress, public string $displayName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your ZULU account has been deleted');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-deletion-completed',
            with: [
                'displayName' => $this->displayName,
                'emailAddress' => $this->emailAddress,
            ],
        );
    }
}

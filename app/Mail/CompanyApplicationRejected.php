<?php

namespace App\Mail;

use App\Models\CompanyApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CompanyApplicationRejected extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CompanyApplication $application
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your application status update — ZULU',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.company-application-rejected',
            with: [
                'application' => $this->application,
            ],
        );
    }
}

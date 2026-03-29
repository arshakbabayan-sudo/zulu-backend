<?php

namespace App\Mail;

use App\Models\CompanyApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CompanyApplicationRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CompanyApplication $application
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Application status update — ZULU SPIN',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.company.application_rejected',
        );
    }
}

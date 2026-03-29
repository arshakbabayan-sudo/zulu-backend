<?php

namespace App\Mail;

use App\Models\CompanyApplication;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CompanyApplicationApproved extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CompanyApplication $application,
        public User $user,
        public string $temporaryPassword
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your application has been approved — Welcome to ZULU',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.company-application-approved',
            with: [
                'application' => $this->application,
                'user' => $this->user,
                'temporaryPassword' => $this->temporaryPassword,
            ],
        );
    }
}

<?php

namespace App\Mail;

use App\Models\CompanySellerApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SellerApplicationRejected extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CompanySellerApplication $application
    ) {
        $this->application->loadMissing('company');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Seller permission request update — ZULU',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.seller-application-rejected',
            with: [
                'application' => $this->application,
                'company' => $this->application->company,
            ],
        );
    }
}

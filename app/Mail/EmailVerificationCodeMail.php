<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the 6-digit B2C email verification code at signup.
 *
 * Not queued — the new user is staring at the verify-email screen waiting
 * for the code to land; worker-backed delay would surface as "code never
 * arrived" complaints.
 */
class EmailVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirm your ZULU email',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.email-verification-code',
            with: [
                'user' => $this->user,
                'code' => $this->code,
            ],
        );
    }
}

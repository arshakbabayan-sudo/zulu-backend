<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent right after a partner (tour agent or tour operator) finishes Step 1 of
 * the /register/<role> flow — account created with their own password,
 * intended_role set on the user row.
 *
 * Content: short reassurance that the account exists and the next step is to
 * submit the company application at /register/<role>/apply. Contains NO
 * password — they already chose one at sign-up.
 */
class PartnerRegistrationWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to ZULU SPIN — next step: complete your application',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.partner.registration_welcome',
            with: [
                'user' => $this->user,
                'intendedRole' => $this->user->intended_role,
                'applyUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/')
                    .'/register/'.($this->user->intended_role ?: 'operator').'/apply',
            ],
        );
    }
}

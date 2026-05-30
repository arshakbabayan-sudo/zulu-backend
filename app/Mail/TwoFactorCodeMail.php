<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the 6-digit email-channel 2FA code to the user at login time.
 *
 * Deliberately NOT queued (no `implements ShouldQueue`): the user is staring
 * at the verification screen waiting for the code; a worker-backed delay
 * would surface as "code never arrived" complaints. Sync send keeps the
 * envelope-to-inbox latency tight.
 */
class TwoFactorCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your ZULU sign-in code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.two-factor-code',
            with: [
                'user' => $this->user,
                'code' => $this->code,
            ],
        );
    }
}

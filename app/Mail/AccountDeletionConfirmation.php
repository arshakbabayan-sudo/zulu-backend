<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountDeletionConfirmation extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $user, public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your ZULU account deletion');
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            view: 'emails.account-deletion-confirmation',
            with: [
                'user' => $this->user,
                'confirmUrl' => $base.'/account/delete/confirm?token='.$this->token,
            ],
        );
    }
}

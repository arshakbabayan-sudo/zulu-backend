<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DataExportReady extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $user, public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your ZULU data export is ready');
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return new Content(
            view: 'emails.data-export-ready',
            with: [
                'user' => $this->user,
                // Public path — sits outside /account/* so ProtectedRoute
                // doesn't bounce un-authenticated users (e.g. clicking the
                // email link from a different device / incognito tab) to
                // /login. Security still relies on the 64-char token.
                'downloadUrl' => $base.'/download?token='.$this->token,
            ],
        );
    }
}

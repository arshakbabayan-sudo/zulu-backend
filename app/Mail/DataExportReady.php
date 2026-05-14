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
                'downloadUrl' => $base.'/account/data-export/download?token='.$this->token,
            ],
        );
    }
}

<?php

namespace App\Mail;

use App\Models\NewsletterSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NewsletterConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public NewsletterSubscription $subscription) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->localisedSubject());
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $lang = $this->subscription->lang ?: 'en';
        $confirmUrl = $base.'/newsletter/confirm?token='.$this->subscription->confirmation_token.'&lang='.$lang;

        return new Content(
            view: 'emails.newsletter-confirmation',
            with: [
                'subscription' => $this->subscription,
                'confirmUrl' => $confirmUrl,
                'lang' => $lang,
            ],
        );
    }

    private function localisedSubject(): string
    {
        return match ($this->subscription->lang) {
            'hy' => 'Հաստատեք ձեր ZULU լրահոս բաժանորդագրությունը',
            'ru' => 'Подтвердите подписку на рассылку ZULU',
            default => 'Confirm your ZULU newsletter subscription',
        };
    }
}

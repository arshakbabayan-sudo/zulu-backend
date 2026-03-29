<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Payment $payment,
        public Invoice $invoice
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment received — ZULU SPIN',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment.received',
            with: [
                'currency' => $this->payment->currency ?? $this->invoice->currency,
            ],
        );
    }
}

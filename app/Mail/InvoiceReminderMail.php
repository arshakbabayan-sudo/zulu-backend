<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Finance group v2 — Phase 2d.
 *
 * Sent when an admin clicks "Send reminder" on an overdue invoice on
 * /platform/invoices. Customer receives a friendly nudge with the invoice
 * number, amount, and a link back to the public order page.
 *
 * Dispatched async via SendInvoiceReminderJob so the admin action returns
 * immediately even when SMTP is slow.
 */
class InvoiceReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invoice #'.$this->invoice->id.' — reminder',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-reminder',
            with: [
                'invoice' => $this->invoice,
                'totalAmount' => (float) $this->invoice->total_amount,
                'currency' => strtoupper($this->invoice->currency ?? ''),
                'reference' => $this->invoice->unique_booking_reference,
            ],
        );
    }
}

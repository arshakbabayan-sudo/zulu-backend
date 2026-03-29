<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class DocumentsReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public ?string $invoicePdfPath,
        public ?string $voucherPdfPath
    ) {}

    public function envelope(): Envelope
    {
        $orderNumber = (string) ($this->invoice->unique_booking_reference ?? $this->invoice->id);

        return new Envelope(
            subject: __('Your Travel Documents for Order #:order', ['order' => $orderNumber]),
        );
    }

    public function build(): static
    {
        $orderNumber = (string) ($this->invoice->unique_booking_reference ?? $this->invoice->id);

        return $this->html(
            '<p>Hello,</p><p>Your paid travel documents are ready for order <strong>#'
            .e($orderNumber)
            .'</strong>. Please find your invoice and voucher attached.</p>'
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];

        if (is_string($this->invoicePdfPath) && $this->invoicePdfPath !== '') {
            $disk = Storage::disk('public')->exists($this->invoicePdfPath) ? 'public' : 'local';
            if (Storage::disk($disk)->exists($this->invoicePdfPath)) {
                $attachments[] = Attachment::fromStorageDisk($disk, $this->invoicePdfPath)
                    ->as('invoice-'.$this->invoice->id.'.pdf')
                    ->withMime('application/pdf');
            }
        }

        if (is_string($this->voucherPdfPath) && $this->voucherPdfPath !== '') {
            $disk = Storage::disk('public')->exists($this->voucherPdfPath) ? 'public' : 'local';
            if (Storage::disk($disk)->exists($this->voucherPdfPath)) {
                $attachments[] = Attachment::fromStorageDisk($disk, $this->voucherPdfPath)
                    ->as('voucher-'.$this->invoice->id.'.pdf')
                    ->withMime('application/pdf');
            }
        }

        return $attachments;
    }
}

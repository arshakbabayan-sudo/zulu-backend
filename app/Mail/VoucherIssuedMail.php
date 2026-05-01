<?php

namespace App\Mail;

use App\Models\Voucher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

class VoucherIssuedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public Voucher $voucher) {}

    public function envelope(): Envelope
    {
        $previousLocale = App::getLocale();
        App::setLocale($this->voucher->language);
        $subject = __('voucher.email.subject', ['number' => $this->voucher->voucher_number]);
        App::setLocale($previousLocale);

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.voucher-issued',
            with: [
                'voucher' => $this->voucher,
                'language' => $this->voucher->language,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if ($this->voucher->pdf_url === null) {
            return [];
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($this->voucher->pdf_url)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', $this->voucher->pdf_url)
                ->as($this->voucher->voucher_number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}

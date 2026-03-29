<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public User $user
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Booking confirmed — ZULU SPIN',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking.confirmed',
        );
    }
}

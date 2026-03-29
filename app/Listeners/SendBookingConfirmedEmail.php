<?php

namespace App\Listeners;

use App\Events\BookingConfirmed;
use App\Mail\BookingConfirmedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendBookingConfirmedEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(BookingConfirmed $event): void
    {
        try {
            Mail::to($event->user->email)
                ->queue(new BookingConfirmedMail($event->booking, $event->user));
        } catch (\Throwable $e) {
            Log::warning('Failed to queue booking confirmed email', [
                'error' => $e->getMessage(),
                'booking_id' => $event->booking->id,
            ]);
        }
    }
}

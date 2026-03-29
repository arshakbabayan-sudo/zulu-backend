<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\User;

class BookingConfirmed
{
    public function __construct(
        public Booking $booking,
        public User $user
    ) {}
}

<?php

return [
    'common' => [
        'view_dashboard' => 'Please check your dashboard for details.',
    ],
    'booking' => [
        'created' => [
            'title' => 'New booking created',
            'message' => 'A new booking (:reference) was created and requires review.',
        ],
        'status_changed' => [
            'subject' => 'Booking status updated',
            'title' => 'Booking status changed',
            'message' => 'Booking :reference status changed to :status.',
        ],
        'statuses' => [
            'reserved' => 'Reserved',
            'sold' => 'Sold',
            'canceled' => 'Canceled',
            'unpaid' => 'Unpaid',
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
        ],
    ],
    'invoice' => [
        'paid' => [
            'subject' => 'Invoice payment received',
            'title' => 'Invoice paid',
            'message' => 'Invoice :reference has been marked as paid. Amount: :amount.',
        ],
    ],
];

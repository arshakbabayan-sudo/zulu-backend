<?php

return [
    'driver' => env('PAYMENT_DRIVER', 'stripe'),
    'stripe' => [
        'key' => env('STRIPE_KEY', ''),
        'secret' => env('STRIPE_SECRET', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        'currency' => env('STRIPE_CURRENCY', 'usd'),
    ],
];

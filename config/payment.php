<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Active payment driver
    |--------------------------------------------------------------------------
    |
    | One of: stripe, arca, idram. Each driver has its own credentials
    | section below. PaymentGatewayService::usingDriver() can also pick
    | a driver per-call when the frontend lets the customer choose.
    |
    */
    'driver' => env('PAYMENT_DRIVER', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Stripe (international cards)
    |--------------------------------------------------------------------------
    */
    'stripe' => [
        'key' => env('STRIPE_KEY', ''),
        'secret' => env('STRIPE_SECRET', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        'currency' => env('STRIPE_CURRENCY', 'usd'),
    ],

    /*
    |--------------------------------------------------------------------------
    | ArCa (Armenian card processor — Ameriabank / VTB / Ardshinbank / etc.)
    |--------------------------------------------------------------------------
    |
    | Endpoint is the bank-specific VPOS base URL. Final URL comes from
    | the merchant agreement.
    |
    */
    'arca' => [
        'endpoint' => env('ARCA_ENDPOINT', ''),
        'client_id' => env('ARCA_CLIENT_ID', ''),
        'username' => env('ARCA_USERNAME', ''),
        'password' => env('ARCA_PASSWORD', ''),
        'webhook_secret' => env('ARCA_WEBHOOK_SECRET', ''),
        'return_url' => env('ARCA_RETURN_URL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idram (Armenian e-wallet + card aggregator)
    |--------------------------------------------------------------------------
    */
    'idram' => [
        'receiver' => env('IDRAM_RECEIVER', ''),
        'secret' => env('IDRAM_SECRET', ''),
        'return_url' => env('IDRAM_RETURN_URL', ''),
        'payment_url' => env('IDRAM_PAYMENT_URL', 'https://money.idram.am/payment.aspx'),
    ],
];
